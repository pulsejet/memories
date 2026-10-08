package gallery.memories.data.remote.assets

import android.util.Base64
import android.util.Log
import gallery.memories.BuildConfig
import org.bouncycastle.math.ec.rfc8032.Ed25519
import org.json.JSONObject

/**
 * Verifies the DSSE envelope over the TUF JS asset targets before any URL
 * or hash from describeApi is trusted or executed.
 * See OtherController::describeApi (jsManifest) and the pinned public key
 * in webpack.manifest-sign-plugin.ts.
 *
 * jsManifest is the plain DSSE envelope {payload, payloadType, signatures}.
 * The signature covers the DSSE pre-auth encoding of the payload, whose
 * decoded bytes are the TUF targets ({targets: {name: {length, hashes,
 * custom: {href}}}}).
 */
object ManifestVerifier {
    private val TAG = ManifestVerifier::class.java.simpleName

    /** Prod Ed25519 public key (base64). */
    const val PUBLIC_KEY_B64 = "aA2fpqrrJHTiCkE9ecQNDR5V1qO7oHppjdJQ7Pj5nhQ="
    const val DEBUG_PUBLIC_KEY_B64 = "MIJxGvOr0LMg9Isyfi5S4cHQsP+v4mFzrsmY2AYKOjs="

    private const val PAYLOAD_TYPE = "application/vnd.tuf+json"

    /**
     * True when the describe carries no manifest, or its DSSE envelope
     * verifies and its TUF targets are fresh. A present manifest with a
     * missing or invalid signature, or expired targets, is refused.
     */
    fun verifyDescribe(describe: JSONObject): Boolean {
        if (describe.isNull("jsManifest")) return true
        val envelope = describe.optJSONObject("jsManifest")
        if (envelope == null) {
            Log.e(TAG, "Bad jsManifest encoding")
            return false
        }
        if (envelope.optString("payloadType") != PAYLOAD_TYPE) {
            Log.e(TAG, "Unexpected DSSE payloadType")
            return false
        }
        val signatures = envelope.optJSONArray("signatures")
        if (signatures == null || signatures.length() != 1) {
            Log.e(TAG, "DSSE envelope must carry exactly one signature")
            return false
        }
        val payload = try {
            Base64.decode(envelope.getString("payload"), Base64.DEFAULT)
        } catch (_: Exception) {
            Log.e(TAG, "Bad DSSE payload encoding")
            return false
        }
        val sig = try {
            Base64.decode(signatures.getJSONObject(0).getString("sig"), Base64.DEFAULT)
        } catch (_: Exception) {
            Log.e(TAG, "Bad DSSE signature encoding")
            return false
        }
        if (sig.size != 64) {
            Log.e(TAG, "Bad signature length")
            return false
        }
        val valid = try {
            // Debug builds also accept the dev key for local development.
            val keys = if (BuildConfig.DEBUG) arrayOf(PUBLIC_KEY_B64, DEBUG_PUBLIC_KEY_B64) else arrayOf(PUBLIC_KEY_B64)
            val pae = preAuthEncoding(PAYLOAD_TYPE, payload)
            keys.any { verify(Base64.decode(it, Base64.DEFAULT), pae, sig) }
        } catch (e: Exception) {
            Log.e(TAG, "Verification error", e)
            false
        }
        if (!valid) {
            Log.e(TAG, "Manifest signature mismatch")
            return false
        }
        if (!targetsFresh(payload)) return false
        return true
    }

    /** True when the TUF targets payload is not expired. */
    private fun targetsFresh(payload: ByteArray): Boolean {
        val expires = try {
            JSONObject(payload.toString(Charsets.UTF_8)).optString("expires", "")
        } catch (_: Exception) {
            Log.e(TAG, "Bad TUF targets encoding")
            return false
        }
        return try {
            if (java.time.Instant.now().isAfter(java.time.Instant.parse(expires))) {
                Log.e(TAG, "TUF targets expired at $expires")
                false
            } else {
                true
            }
        } catch (_: Exception) {
            Log.e(TAG, "Bad TUF targets expiry")
            false
        }
    }

    /** DSSE pre-auth encoding: "DSSEv1" + SP + len(type) + SP + type + SP + len(payload) + SP + payload. */
    private fun preAuthEncoding(payloadType: String, payload: ByteArray): ByteArray {
        val typeBytes = payloadType.toByteArray(Charsets.UTF_8)
        return "DSSEv1 ${typeBytes.size} ".toByteArray(Charsets.US_ASCII) +
                typeBytes + " ${payload.size} ".toByteArray(Charsets.US_ASCII) + payload
    }

    /** Ed25519 verification (RFC 8032) over raw 32-byte public keys via Bouncy Castle. */
    private fun verify(pub: ByteArray, msg: ByteArray, sig: ByteArray): Boolean {
        if (pub.size != Ed25519.PUBLIC_KEY_SIZE || sig.size != Ed25519.SIGNATURE_SIZE) return false
        return try {
            Ed25519.verify(sig, 0, pub, 0, msg, 0, msg.size)
        } catch (_: Exception) {
            false
        }
    }
}
