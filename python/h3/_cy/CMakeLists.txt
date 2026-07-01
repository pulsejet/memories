list(APPEND CMAKE_MODULE_PATH ${CMAKE_CURRENT_SOURCE_DIR})

include_directories(${CMAKE_CURRENT_SOURCE_DIR})

macro(add_cython_file filename)
    add_custom_command(
      OUTPUT "${filename}.c"
      COMMENT
        "Making ${CMAKE_CURRENT_BINARY_DIR}/${filename}.c from ${CMAKE_CURRENT_SOURCE_DIR}/${filename}.pyx"
      COMMAND Python::Interpreter -m cython
              "${CMAKE_CURRENT_SOURCE_DIR}/${filename}.pyx" --output-file "${filename}.c" -I ${CMAKE_CURRENT_SOURCE_DIR}
      DEPENDS "${filename}.pyx"
      VERBATIM)

    python_add_library(${filename} MODULE "${filename}.c" WITH_SOABI)

    set_property(TARGET ${filename} PROPERTY C_STANDARD 99)
    target_link_libraries(${filename} PRIVATE h3)
    install(TARGETS ${filename} LIBRARY DESTINATION ${SKBUILD_PROJECT_NAME}/_cy)
endmacro()

# GLOB pattern is recommended against
# https://cmake.org/cmake/help/v3.14/command/file.html?highlight=file#filesystem
add_cython_file(cells)
add_cython_file(edges)
add_cython_file(error_system)
add_cython_file(latlng)
add_cython_file(memory)
add_cython_file(vertex)

add_cython_file(to_multipoly)
add_cython_file(util)

# Include pyx and pxd files in distribution for use by Cython API
install(
    FILES
        cells.pxd
        cells.pyx
        edges.pxd
        edges.pyx
        error_system.pyx
        h3lib.pxd
        latlng.pxd
        latlng.pyx
        memory.pxd
        memory.pyx
        util.pxd
        util.pyx
        vertex.pxd
        vertex.pyx
    DESTINATION
        ${SKBUILD_PROJECT_NAME}/_cy
)
