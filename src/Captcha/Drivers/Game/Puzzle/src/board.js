/** Ячейка поля под точкой (координаты относительно поля) или −1. */
export function cellAt(x, y, rows, cols, W, H) {
    if (x < 0 || y < 0 || x >= W || y >= H) return -1;
    return Math.floor(y / (H / rows)) * cols + Math.floor(x / (W / cols));
}
