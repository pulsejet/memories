from .h3lib cimport H3Error

cpdef error_code_to_exception(H3Error err)
cdef check_for_error(H3Error err)
cdef check_for_error_msg(H3Error err, str msg)
cpdef H3Error get_H3_ERROR_END()
