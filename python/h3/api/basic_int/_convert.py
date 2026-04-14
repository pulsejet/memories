from ... import _cy


def _in_scalar(x):
    return x


_out_scalar = _in_scalar


def _in_collection(cells):
    it = list(cells)

    return _cy.iter_to_mv(it)


_out_collection = list
