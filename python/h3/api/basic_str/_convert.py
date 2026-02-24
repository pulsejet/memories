from ... import _cy

_in_scalar = _cy.str_to_int
_out_scalar = _cy.int_to_str


def _in_collection(cells):
    it = [_cy.str_to_int(h) for h in cells]

    return _cy.iter_to_mv(it)


def _out_collection(mv):
    return list(_cy.int_to_str(h) for h in mv)
