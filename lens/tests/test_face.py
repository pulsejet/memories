"""Face geometry and inference contracts without weights, downloads or service credentials."""

import io
import os
import unittest
from dataclasses import replace
from types import SimpleNamespace
from unittest.mock import Mock, patch

import numpy as np
from PIL import Image

with patch.dict(os.environ, {
    "PYTHON_DOTENV_DISABLED": "1",
    "NEXTCLOUD_URL": "http://test",
    "NC_USER": "test",
    "NC_TOKEN": "test",
    "QDRANT_URL": "http://test",
    "EMBEDDING_MODEL_REVISION": "test",
    "SENTENCE_MODEL_REVISION": "test",
    "SCHEMA_MODEL_REVISION": "test",
    "FACE_DET_URL": "http://test/det.onnx",
    "FACE_REC_URL": "http://test/rec.onnx",
    "FACE_DET_SHA": "0" * 64,
    "FACE_REC_SHA": "0" * 64,
    "FACE_DET_THRESHOLD": "0.6",
    "FACE_DET_MAX_SIDE": "1920",
    "FACE_VERSION": "2",
    "DEVICE": "cpu",
}):
    from config import _face_config, config
    from faces.pipeline import match_faces
    from models.face import DST_TEMPLATE, FaceModel, _decode_stride, _format_face, _similarity_matrix
    from nextcloud.client import Face


class FaceGeometryTest(unittest.TestCase):
    """Image orientation, clipping, padding and alignment regressions."""

    def test_all_exif_orientations(self):
        """Decode every rotation/mirror tag into its expected display pixels."""

        pixels = np.arange(18, dtype=np.uint8).reshape(2, 3, 3)
        expected = [
            pixels,
            pixels[:, ::-1],
            pixels[::-1, ::-1],
            pixels[::-1],
            pixels.transpose(1, 0, 2),
            np.rot90(pixels, -1),
            pixels.transpose(1, 0, 2)[::-1, ::-1],
            np.rot90(pixels, 1),
        ]
        for orientation, rgb in enumerate(expected, 1):
            with self.subTest(orientation=orientation):
                image = Image.fromarray(pixels)
                exif = Image.Exif()
                exif[274] = orientation
                data = io.BytesIO()
                image.save(data, format="PNG", exif=exif)
                decoded = FaceModel.decode_image(data.getvalue())
                np.testing.assert_array_equal(decoded, rgb[:, :, ::-1])

    def test_clip_endpoints_without_clipping_landmarks(self):
        """A border face stays inside the image while alignment keeps original landmarks."""

        points = DST_TEMPLATE + [30, -60]
        face = _format_face([70, -10, 40, 40], .9, points, 100, 100)
        self.assertIsNotNone(face)
        np.testing.assert_allclose([face[k] for k in ("x", "y", "w", "h")], [.7, 0, .3, .3])
        np.testing.assert_allclose(face["landmarks5"], points / 100)

    def test_unusable_boxes_are_discarded(self):
        """Padding-only, tiny, negative and non-finite boxes never reach indexing."""

        for box in ([100, 0, 40, 40], [-40, 0, 20, 40], [0, 0, 19, 40],
                    [0, 0, -30, 40], [np.nan, 0, 30, 40], [0, 0, np.inf, 40]):
            with self.subTest(box=box):
                self.assertIsNone(_format_face(box, .9, DST_TEMPLATE, 100, 100))

    def test_invalid_landmarks_and_scores_are_discarded(self):
        """Malformed, coincident and collinear landmarks cannot define an aligned face."""

        for points in ([], np.zeros((5, 2)), np.ones((4, 2)),
                       np.full((5, 2), np.nan), np.arange(10).reshape(5, 2)):
            with self.subTest(points=points):
                self.assertIsNone(_format_face([0, 0, 40, 40], .9, points, 100, 100))
                with self.assertRaises(ValueError):
                    _similarity_matrix(np.asarray(points))

        for score in (np.nan, np.inf, -1, 2):
            self.assertIsNone(_format_face([0, 0, 40, 40], score, DST_TEMPLATE, 100, 100))

    def test_alignment_inverts_known_similarity(self):
        """Recover known rotation, scale and translation from the five landmarks."""

        angle = .4
        rotation = np.array([[np.cos(angle), -np.sin(angle)], [np.sin(angle), np.cos(angle)]])
        points = 2.5 * (DST_TEMPLATE @ rotation.T) + [40, 80]
        matrix = _similarity_matrix(points)
        aligned = np.column_stack([points, np.ones(5)]) @ matrix.T
        np.testing.assert_allclose(aligned, DST_TEMPLATE, atol=1e-3)

    def test_invalid_image_inputs(self):
        """Reject empty, grayscale, RGBA and floating-point input before OpenCV inference."""

        for image in (np.zeros((0, 10, 3), np.uint8), np.zeros((10, 10), np.uint8),
                      np.zeros((10, 10, 4), np.uint8), np.zeros((10, 10, 3), np.float32)):
            with self.subTest(shape=image.shape, dtype=image.dtype):
                with self.assertRaises(ValueError):
                    FaceModel._preprocess(image)

    def test_thin_image_resize_and_padding(self):
        """Extreme aspect ratios keep at least one pixel and zero-pad to whole strides."""

        settings = replace(config, face=replace(config.face, det_max_side=320))
        with patch("models.face.config", settings):
            blob, width, height, padded_width = FaceModel._preprocess(np.ones((1, 10000, 3), np.uint8))
        self.assertEqual((width, height, padded_width), (320, 1, 320))
        self.assertEqual(blob.shape, (1, 3, 32, 320))
        np.testing.assert_array_equal(blob[:, :, 0], 1)
        np.testing.assert_array_equal(blob[:, :, 1:], 0)

    def test_invalid_detection_is_filtered_before_nms(self):
        """Bad predictions cannot suppress or corrupt a usable detection."""

        decoded = ([[0, 0, np.inf, 80], [20, 20, 60, 60]], [.99, .9], [DST_TEMPLATE, DST_TEMPLATE])
        with patch("models.face._decode_stride", side_effect=[decoded, None, None]):
            faces = FaceModel()._decode([], 128, 100, 100)
        self.assertEqual(len(faces), 1)
        self.assertAlmostEqual(faces[0]["x"], .2)

    def test_nonfinite_predictions_are_not_clipped_into_valid_scores(self):
        """Reject infinite confidence or coordinates rather than promoting them to detections."""

        for output_index in (0, 3, 6, 9):
            for value in (np.nan, np.inf, -np.inf):
                with self.subTest(output=output_index, value=value):
                    outputs = [np.ones((1, 1, size)) for size in [1] * 6 + [4] * 3 + [10] * 3]
                    outputs[output_index][0, 0, 0] = value
                    self.assertIsNone(_decode_stride(outputs, 0, 8, 32))


class FaceRecognitionTest(unittest.TestCase):
    """Recognition preserves order and rejects unusable vectors."""

    def test_empty_faces_do_not_run_inference(self):
        """No-face input succeeds even without a loaded recognizer."""

        model = FaceModel()
        self.assertEqual(model.embed_crops([]), [])
        self.assertEqual(model.embed_image_faces(np.zeros((10, 10, 3), np.uint8), []), [])

    def test_multiple_faces_use_batch_one_rgb_and_preserve_order(self):
        """Every crop gets one float32 RGB run and its own normalized result."""

        model = FaceModel()
        model._rec_input = "data"
        model._rec = Mock()
        model._rec.run.side_effect = [[np.eye(128, dtype=np.float32)[i:i + 1] * 2] for i in range(3)]
        crops = [np.full((112, 112, 3), [i, i + 1, i + 2], dtype=np.uint8) for i in range(3)]
        vectors = np.array(model.embed_crops(crops))
        np.testing.assert_allclose(vectors, np.eye(128)[:3])
        for i, call in enumerate(model._rec.run.call_args_list):
            blob = call.args[1]["data"]
            self.assertEqual(blob.shape, (1, 3, 112, 112))
            self.assertEqual(blob.dtype, np.float32)
            np.testing.assert_array_equal(blob[0, :, 0, 0], [i + 2, i + 1, i])

    def test_invalid_embeddings_raise(self):
        """Zero, non-finite and incorrectly shaped output cannot enter the vector store."""

        model = FaceModel()
        model._rec = Mock()
        for output in (np.zeros((1, 128)), np.full((1, 128), np.nan),
                       np.full((1, 128), np.inf), np.ones((2, 128)), np.ones((1, 64))):
            with self.subTest(shape=output.shape):
                model._rec.run.return_value = [output]
                with self.assertRaises(ValueError):
                    model.embed_crops([np.zeros((112, 112, 3), np.uint8)])

    def test_wrong_crop_size_raises(self):
        """Recognition requires aligned crops, not arbitrary image dimensions."""

        with self.assertRaisesRegex(ValueError, "112x112"):
            FaceModel().embed_crops([np.zeros((100, 100, 3), np.uint8)])


class FaceRuntimeTest(unittest.TestCase):
    """Runtime selection and pinned model shape validation."""

    def test_cpu_device_and_thread_budget(self):
        """Explicit CPU selection must not initialize CUDA even when it is installed."""

        det, rec = sessions()
        settings = replace(config, device="cpu", torch_num_threads=2)
        with patch("models.face.config", settings), \
                patch("models.face.ort.get_available_providers", return_value=["CUDAExecutionProvider"]), \
                patch("models.face.ort.InferenceSession", side_effect=[det, rec]) as create:
            model = FaceModel()
            self.assertEqual(model.load(), 128)
            self.assertEqual(model.device(), "cpu")
        for call in create.call_args_list:
            self.assertEqual(call.kwargs["providers"], ["CPUExecutionProvider"])
            self.assertEqual(call.kwargs["sess_options"].intra_op_num_threads, 2)

    def test_unavailable_explicit_cuda_raises(self):
        """An explicit unavailable device is an actionable error, not a silent fallback."""

        with patch("models.face.config", replace(config, device="cuda")), \
                patch("models.face.ort.get_available_providers", return_value=["CPUExecutionProvider"]):
            with self.assertRaisesRegex(RuntimeError, "CUDA provider"):
                FaceModel().load()

    def test_wrong_recognition_contract_raises(self):
        """Reject model files with an incompatible crop or descriptor shape at load time."""

        for shape, output in (([2, 3, 112, 112], [1, 128]), ([1, 3, 112, 112], [1, 64])):
            det, rec = sessions()
            rec.get_inputs.return_value = [SimpleNamespace(name="data", shape=shape)]
            rec.get_outputs.return_value = [SimpleNamespace(shape=output)]
            with patch("models.face.config", replace(config, device="cpu")), \
                    patch("models.face.ort.InferenceSession", side_effect=[det, rec]):
                with self.assertRaises(RuntimeError):
                    FaceModel().load()

    def test_static_detection_shape_rejected(self):
        """A fixed-size detector cannot support the variable-resolution preprocessing path."""

        det, rec = sessions()
        det.get_inputs.return_value = [SimpleNamespace(name="input", shape=[1, 3, 640, 640])]
        with patch("models.face.config", replace(config, device="cpu")), \
                patch("models.face.ort.InferenceSession", side_effect=[det, rec]):
            with self.assertRaisesRegex(RuntimeError, "dynamic height and width"):
                FaceModel().load()

    def test_reordered_detector_outputs_rejected(self):
        """Do not silently decode a different tensor layout as boxes and landmarks."""

        det, rec = sessions()
        det.get_outputs.return_value.reverse()
        with patch("models.face.config", replace(config, device="cpu")), \
                patch("models.face.ort.InferenceSession", side_effect=[det, rec]):
            with self.assertRaisesRegex(RuntimeError, "cls/obj/bbox/kps"):
                FaceModel().load()

    def test_nonfinite_detection_threshold_rejected(self):
        """NaN must not silently disable detection filtering."""

        for value in ("nan", "inf", "-inf"):
            with patch.dict(os.environ, {"FACE_DET_THRESHOLD": value}):
                with self.assertRaises(RuntimeError):
                    _face_config()


class MatchFacesTest(unittest.TestCase):
    """Geometry matching restores identity only within the storage scope."""

    DET = {"x": 0.1, "y": 0.1, "w": 0.2, "h": 0.2, "det_score": 0.9}

    def old(self, **kwargs):
        """One stored face row; geometry matches DET unless overridden."""

        params = {
            "id": 5, "x": 0.1, "y": 0.1, "w": 0.2, "h": 0.2,
            "cluster_id": 7, "cluster_owner": "home::a", "embed_version": 2,
        }
        params.update(kwargs)

        return Face(**params)

    def test_same_scope_restores_cluster(self):
        """Identical geometry and owner keeps the id and cluster."""

        [matched] = match_faces([self.DET], [self.old()], 2, "home::a")
        self.assertEqual((matched["id"], matched["cluster_id"]), (5, 7))

    def test_moved_scope_mints_unassigned(self):
        """A cross-storage move keeps the id but drops the foreign cluster."""

        [matched] = match_faces([self.DET], [self.old()], 2, "home::b")
        self.assertEqual((matched["id"], matched["cluster_id"]), (5, None))

    def test_unknown_owner_restores(self):
        """Legacy rows without an owner behave exactly like before."""

        [matched] = match_faces([self.DET], [self.old(cluster_owner=None)], 2, "home::b")
        self.assertEqual((matched["id"], matched["cluster_id"]), (5, 7))

    def test_unassigned_stays_unassigned(self):
        """A stored null cluster is echoed, never invented."""

        [matched] = match_faces([self.DET], [self.old(cluster_id=None)], 2, "home::a")
        self.assertEqual((matched["id"], matched["cluster_id"]), (5, None))

    def test_version_mismatch_mints_fresh(self):
        """Stale-version rows do not participate at any scope."""

        [matched] = match_faces([self.DET], [self.old(embed_version=1)], 2, "home::a")
        self.assertEqual(matched["cluster_id"], None)
        self.assertNotEqual(matched["id"], 5)


def sessions():
    """Minimal ORT metadata doubles for the two pinned model contracts."""

    det, rec = Mock(), Mock()
    det.get_inputs.return_value = [SimpleNamespace(name="input", shape=[1, 3, "height", "width"])]
    det.get_outputs.return_value = [
        SimpleNamespace(name=f"{kind}_{stride}")
        for kind in ("cls", "obj", "bbox", "kps") for stride in (8, 16, 32)
    ]
    det.get_providers.return_value = ["CPUExecutionProvider"]
    rec.get_inputs.return_value = [SimpleNamespace(name="data", shape=[1, 3, 112, 112])]
    rec.get_outputs.return_value = [SimpleNamespace(shape=[1, 128])]

    return det, rec
