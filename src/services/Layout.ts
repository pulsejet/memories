import justifiedLayout from "justified-layout";

/**
 * Generate the layout matrix.
 *
 * If we are in square mode, do this manually to get non-uniformity.
 * Otherwise, use flickr/justified-layout (at least for now).
 */
export function getLayout(
    input: { width: number, height: number }[],
    opts: {
        rowWidth: number,
        rowHeight: number,
        squareMode: boolean,
        numCols: number,
    }
): {
    top: number,
    left: number,
    width: number,
    height: number,
}[] {
    if (!opts.squareMode) {
        return justifiedLayout((input), {
            containerPadding: 0,
            boxSpacing: 0,
            containerWidth: opts.rowWidth,
            targetRowHeight: opts.rowHeight,
            targetRowHeightTolerance: 0.1,
        }).boxes;
    }

    // Binary flags
    const FLAG_USE = 1;
    const FLAG_USED = 2;
    const FLAG_USE4 = 4;

    // Create 2d matrix to work in
    const origRowLen = Math.ceil(input.length / opts.numCols);
    const matrix: number[][] = new Array(origRowLen * 3); // todo: dynamic length
    for (let i = 0; i < matrix.length; i++) {
        matrix[i] = new Array(opts.numCols).fill(0);
    }

    // Useful for debugging
    const printMatrix = () => {
        let str = '';
        for (let i = 0; i < matrix.length; i++) {
            const rstr = matrix[i].map(v => v.toString(2).padStart(4, '0')).join(' ');
            str += i.toString().padStart(2) + ' | ' + rstr + '\n';
        }
        console.log(str);
    }

    // Fill in the matrix
    let row = 0;
    let col = 0;
    let photoId = 0;
    while (photoId < input.length) {
        // Check if we reached the end of row
        if (col >= opts.numCols) {
            row++; col = 0;
        }

        // Check if already used
        if (matrix[row][col] & FLAG_USED) {
            col++; continue;
        }

        // Use this slot
        matrix[row][col] |= FLAG_USE;
        photoId++;

        // Check if previous row has something used
        // or something beside this is used
        // We don't do these one after another
        if ((row > 0 && matrix[row-1].some(v => v & FLAG_USED)) ||
            (col > 0 && matrix[row][col-1] & FLAG_USED) ||
            (col < opts.numCols-1 && matrix[row][col+1] & FLAG_USED)
        ) {
            col++; continue;
        }

        // Check if we can use 4 blocks
        let canUse4 =
            // We have enough space
            (row + 1 < matrix.length && col+1 < opts.numCols) &&
            // Nothing used in vicinity (redundant check)
            !(matrix[row+1][col] & FLAG_USED) &&
            !(matrix[row][col+1] & FLAG_USED) &&
            !(matrix[row+1][col+1] & FLAG_USED) &&
            // This cannot end up being a widow (conservative)
            (input.length-photoId-1 >= ((opts.numCols-col-2) + (opts.numCols-2)));

        // Use four with 60% probability
        if (canUse4 && Math.random() < 0.6) {
            matrix[row][col] |= FLAG_USE4;
            matrix[row+1][col] |= FLAG_USED;
            matrix[row][col+1] |= FLAG_USED;
            matrix[row+1][col+1] |= FLAG_USED;
        }

        // Go ahead
        col++;
    }

    // REMOVE BEFORE PUSH
    if (input.length == 10)
        printMatrix();

    // Square layout matrix
    const absMatrix: {
        top: number,
        left: number,
        width: number,
        height: number,
    }[] = [];

    let currTop = 0;
    row = 0; col = 0; photoId = 0;
    while (photoId < input.length) {
        // Check if we reached the end of row
        if (col >= opts.numCols) {
            row++; col = 0;
            currTop += opts.rowHeight;
            continue;
        }

        // Skip if used
        if (!(matrix[row][col] & FLAG_USE)) {
            col++; continue;
        }

        // Create basic object
        const sqsize = opts.rowHeight;
        const p = {
            top: currTop,
            left: col * sqsize,
            width: sqsize,
            height: sqsize,
        }

        // Use twice the space
        if (matrix[row][col] & FLAG_USE4) {
            p.width *= 2;
            p.height *= 2;
            col += 2;
        } else {
            col += 1;
        }

        absMatrix.push(p);
        photoId++;
    }

    return absMatrix;
}