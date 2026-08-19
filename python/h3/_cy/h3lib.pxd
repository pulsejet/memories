# cython: c_string_type=unicode, c_string_encoding=utf8
from cpython cimport bool
from libc.stdint cimport uint32_t, uint64_t, int64_t

ctypedef object H3str

cdef extern from 'h3api.h':
    cdef int H3_VERSION_MAJOR
    cdef int H3_VERSION_MINOR
    cdef int H3_VERSION_PATCH

    ctypedef uint64_t H3int 'H3Index'

    ctypedef uint32_t H3Error
    ctypedef enum H3ErrorCodes:
        E_SUCCESS = 0
        E_FAILED = 1
        E_DOMAIN = 2
        E_LATLNG_DOMAIN = 3
        E_RES_DOMAIN = 4
        E_CELL_INVALID = 5
        E_DIR_EDGE_INVALID = 6
        E_UNDIR_EDGE_INVALID = 7
        E_VERTEX_INVALID = 8
        E_PENTAGON = 9
        E_DUPLICATE_INPUT = 10
        E_NOT_NEIGHBORS = 11
        E_RES_MISMATCH = 12
        E_MEMORY_ALLOC = 13
        E_MEMORY_BOUNDS = 14
        E_OPTION_INVALID = 15
        E_INDEX_INVALID = 16
        E_BASE_CELL_DOMAIN = 17
        E_DIGIT_DOMAIN = 18
        E_DELETED_DIGIT = 19
        H3_ERROR_END  # sentinel value

    ctypedef struct LatLng:
        double lat  # in radians
        double lng  # in radians

    ctypedef struct CellBoundary:
        int num_verts 'numVerts'
        LatLng verts[10]  # MAX_CELL_BNDRY_VERTS

    ctypedef struct CoordIJ:
        int i
        int j

    ctypedef struct LinkedLatLng:
        LatLng data 'vertex'
        LinkedLatLng *next

    # renaming these for clarity
    ctypedef struct LinkedGeoLoop:
        LinkedLatLng *data 'first'
        LinkedLatLng *_data_last 'last'  # not needed in Cython bindings
        LinkedGeoLoop *next

    ctypedef struct LinkedGeoPolygon:
        LinkedGeoLoop *data 'first'
        LinkedGeoLoop *_data_last 'last'  # not needed in Cython bindings
        LinkedGeoPolygon *next

    ctypedef struct GeoLoop:
        int numVerts
        LatLng *verts

    ctypedef struct GeoPolygon:
        GeoLoop geoloop
        int numHoles
        GeoLoop *holes

    int isValidCell(H3int h) nogil
    int isPentagon(H3int h) nogil
    int isResClassIII(H3int h) nogil
    int isValidDirectedEdge(H3int edge) nogil
    int isValidVertex(H3int v) nogil
    int isValidIndex(H3int h) nogil

    double degsToRads(double degrees) nogil
    double radsToDegs(double radians) nogil

    int getResolution(H3int h) nogil
    int getBaseCellNumber(H3int h) nogil
    H3Error getIndexDigit(H3int h, int res, int *out) nogil
    H3Error constructCell(int res, int baseCellNumber, const int *digits, H3int *out) nogil

    H3Error latLngToCell(const LatLng *g, int res, H3int *out) nogil
    H3Error cellToLatLng(H3int h, LatLng *) nogil
    H3Error gridDistance(H3int h1, H3int h2, int64_t *distance) nogil

    H3Error cellToVertex(H3int cell, int vertexNum, H3int *out) nogil
    H3Error cellToVertexes(H3int cell, H3int *vertexes) nogil
    H3Error vertexToLatLng(H3int vertex, LatLng *coord) nogil

    H3Error maxGridDiskSize(int k, int64_t *out) nogil # num/out/N?
    H3Error gridDisk(H3int h, int k, H3int *out) nogil

    H3Error cellToParent(     H3int h, int parentRes, H3int *parent) nogil
    H3Error cellToCenterChild(H3int h, int childRes,  H3int *child) nogil
    H3Error cellToChildPos(H3int child, int parentRes, int64_t *out) nogil
    H3Error childPosToCell(int64_t childPos, H3int parent, int childRes, H3int *child) nogil

    H3Error cellToChildrenSize(H3int h, int childRes, int64_t *num) nogil # num/out/N?
    H3Error cellToChildren(    H3int h, int childRes, H3int *children) nogil

    H3Error compactCells(
        const H3int *cells_u,
              H3int *cells_c,
        const int num_u
    ) nogil
    H3Error uncompactCellsSize(
        const H3int *cells_c,
        const int64_t    num_c,
        const int res,
        int64_t *num_u
    ) nogil
    H3Error uncompactCells(
        const H3int *cells_c,
        const int        num_c,
        H3int       *cells_u,
        const int        num_u,
        const int res
    ) nogil

    H3Error getNumCells(int res, int64_t *out) nogil
    int pentagonCount() nogil
    int res0CellCount() nogil
    H3Error getPentagons(int res, H3int *out) nogil
    H3Error getRes0Cells(H3int *out) nogil

    H3Error gridPathCellsSize(H3int start, H3int end, int64_t *size) nogil
    H3Error gridPathCells(H3int start, H3int end, H3int *out) nogil

    H3Error getHexagonAreaAvgKm2(int res, double *out) nogil
    H3Error getHexagonAreaAvgM2(int res, double *out) nogil

    H3Error cellAreaRads2(H3int h, double *out) nogil
    H3Error cellAreaKm2(H3int h, double *out) nogil
    H3Error cellAreaM2(H3int h, double *out) nogil

    H3Error maxFaceCount(H3int h, int *out) nogil
    H3Error getIcosahedronFaces(H3int h3, int *out) nogil

    H3Error cellToLocalIj(H3int origin, H3int h3, uint32_t mode, CoordIJ *out) nogil
    H3Error localIjToCell(H3int origin, const CoordIJ *ij, uint32_t mode, H3int *out) nogil

    H3Error gridDiskDistances(H3int origin, int k, H3int *out, int *distances) nogil
    H3Error gridRing(H3int origin, int k, H3int *out) nogil
    H3Error gridRingUnsafe(H3int origin, int k, H3int *out) nogil

    H3Error areNeighborCells(H3int origin, H3int destination, int *out) nogil
    H3Error cellsToDirectedEdge(H3int origin, H3int destination, H3int *out) nogil
    H3Error getDirectedEdgeOrigin(H3int edge, H3int *out) nogil
    H3Error getDirectedEdgeDestination(H3int edge, H3int *out) nogil
    H3Error originToDirectedEdges(H3int origin, H3int *edges) nogil
    # todo: directedEdgeToCells

    H3Error getHexagonEdgeLengthAvgKm(int res, double *out) nogil
    H3Error getHexagonEdgeLengthAvgM(int res, double *out) nogil

    H3Error edgeLengthRads(H3int edge, double *out) nogil
    H3Error edgeLengthKm(H3int edge, double *out) nogil
    H3Error edgeLengthM(H3int edge, double *out) nogil

    H3Error cellToBoundary(H3int h3, CellBoundary *gp) nogil
    H3Error directedEdgeToBoundary(H3int edge, CellBoundary *gb) nogil

    double greatCircleDistanceRads(const LatLng *a, const LatLng *b) nogil
    double greatCircleDistanceKm(const LatLng *a, const LatLng *b) nogil
    double greatCircleDistanceM(const LatLng *a, const LatLng *b) nogil

    H3Error cellsToLinkedMultiPolygon(const H3int *h3Set, const int numCells, LinkedGeoPolygon *out)
    void destroyLinkedMultiPolygon(LinkedGeoPolygon *polygon)

    H3Error maxPolygonToCellsSize(const GeoPolygon *geoPolygon, int res, uint32_t flags, uint64_t *count)
    H3Error polygonToCells(const GeoPolygon *geoPolygon, int res, uint32_t flags, H3int *out)

    H3Error maxPolygonToCellsSizeExperimental(const GeoPolygon *geoPolygon, int res, uint32_t flags, uint64_t *count)
    H3Error polygonToCellsExperimental(const GeoPolygon *geoPolygon, int res, uint32_t flags, uint64_t sz, H3int *out)

    # ctypedef struct GeoMultiPolygon:
    #     int numPolygons
    #     GeoPolygon *polygons

    # int hexRange(H3int origin, int k, H3int *out)

    # int hexRangeDistances(H3int origin, int k, H3int *out, int *distances)

    # int hexRanges(H3int *h3Set, int length, int k, H3int *out)

    # void h3SetToLinkedGeo(const H3int *h3Set, const int numCells, LinkedGeoPolygon *out)

    # void destroyLinkedPolygon(LinkedGeoPolygon *polygon)

    # H3int stringToH3(const char *str)

    # void h3ToString(H3int h, char *str, size_t sz)

    # void getH3intesFromUnidirectionalEdge(H3int edge, H3int *originDestination)
