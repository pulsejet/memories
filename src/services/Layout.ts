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
        allowBreakout: boolean,
        seed: number,
    }
): {
    top: number,
    left: number,
    width: number,
    height: number,
    rowHeight?: number,
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

    // RNG
    const rand = mulberry32(opts.seed);

    // Binary flags
    const FLAG_USE          = 1 << 0;
    const FLAG_USED         = 1 << 1;
    const FLAG_USE4         = 1 << 2;
    const FLAG_USE6         = 1 << 3;
    const FLAG_BREAKOUT     = 1 << 4;

    // Create 2d matrix to work in
    const matrix: number[][] = [];

    // Fill in the matrix
    let row = 0;
    let col = 0;
    let photoId = 0;
    while (photoId < input.length) {
        // Check if we reached the end of row
        if (col >= opts.numCols) {
            row++; col = 0;
        }

        // Make sure we have this and the next few rows
        while (row + 3 >= matrix.length) {
            matrix.push(new Array(opts.numCols).fill(0));
        }

        // Check if already used
        if (matrix[row][col] & FLAG_USED) {
            col++; continue;
        }

        // Use this slot
        matrix[row][col] |= FLAG_USE;

        // Check if previous row has something used
        // or something beside this is used
        // We don't do these one after another
        if (!opts.allowBreakout ||
            (row > 0 && matrix[row-1].some(v => v & FLAG_USED)) ||
            (col > 0 && matrix[row][col-1] & FLAG_USED)
        ) {
            photoId++; col++; continue;
        }

        // Number of photos left
        const numLeft = input.length-photoId-1;
        // Number of photos needed for perfect fill after using n
        const needFill = (n: number) => ((opts.numCols-col-2) + (n/2-1)*(opts.numCols-2));

        let canUse4 =
            // We have enough space
            (row + 1 < matrix.length && col+1 < opts.numCols) &&
            // This cannot end up being a widow (conservative)
            // Also make sure the next row gets fully filled, otherwise looks weird
            (numLeft === needFill(4) || numLeft >= needFill(4)+opts.numCols);

        let canUse6 =
            // Image is portrait
            input[photoId].height > input[photoId].width &&
            // We have enough space
            (row + 2 < matrix.length && col+1 < opts.numCols) &&
            // This cannot end up being a widow (conservative)
            // Also make sure the next row gets fully filled, otherwise looks weird
            (numLeft === needFill(6) || numLeft >= needFill(6)+2*opts.numCols);

        let canBreakout =
            // First column only
            col === 0 &&
            // Image is landscape
            input[photoId].width > input[photoId].height &&
            // The next row gets filled
            (numLeft === 0 || numLeft >= opts.numCols);

        // Full width breakout
        if (canBreakout && rand() < (input.length > 0 ? 0.2 : 0.1)) {
            matrix[row][col] |= FLAG_BREAKOUT;
            for (let i = 1; i < opts.numCols; i++) {
                matrix[row][i] |= FLAG_USED;
            }
        }

        // Use 6 vertically
        else if (canUse6 && rand() < 0.2) {
            matrix[row][col] |= FLAG_USE6;
            matrix[row+1][col] |= FLAG_USED;
            matrix[row+2][col] |= FLAG_USED;
            matrix[row][col+1] |= FLAG_USED;
            matrix[row+1][col+1] |= FLAG_USED;
            matrix[row+2][col+1] |= FLAG_USED;
        }

        // Use 4 box
        else if (canUse4 && rand() < ((col % 2) ? 0.67 : 0.4)) {
            matrix[row][col] |= FLAG_USE4;
            matrix[row+1][col] |= FLAG_USED;
            matrix[row][col+1] |= FLAG_USED;
            matrix[row+1][col+1] |= FLAG_USED;
        }

        // Go ahead
        photoId++; col++;
    }

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
            rowHeight: opts.rowHeight,
        }

        // Use twice the space
        const v = matrix[row][col];
        if (v & FLAG_USE4) {
            p.width *= 2;
            p.height *= 2;
            col += 2;
        } else if (v & FLAG_USE6) {
            p.width *= 2;
            p.height *= 3;
            col += 2;
        } else if (v & FLAG_BREAKOUT) {
            p.width *= opts.numCols;
            p.height = input[photoId].height * p.width / input[photoId].width;
            p.rowHeight = p.height;
            col += opts.numCols;
        } else {
            col++;
        }

        absMatrix.push(p);
        photoId++;
    }

    return absMatrix;
}

function flagMatrixStr(matrix: number[][], numFlag: number) {
    let str = '';
    for (let i = 0; i < matrix.length; i++) {
        const rstr = matrix[i].map(v => v.toString(2).padStart(numFlag, '0')).join(' ');
        str += i.toString().padStart(2) + ' | ' + rstr + '\n';
    }
    return str;
}

function mulberry32(a: number) {
    return function() {
      var t = a += 0x6D2B79F5;
      t = Math.imul(t ^ t >>> 15, t | 1);
      t ^= t + Math.imul(t ^ t >>> 7, t | 61);
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    }
}