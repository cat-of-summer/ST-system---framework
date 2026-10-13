import { createDriver, COLORS } from '../../assets/engine/src/index.js';
import { PuzzleSim } from './sim.js';
import { puzzleTable } from './table.js';

function icon(ctx, x, y, r) {
    ctx.fillStyle = COLORS.orange;
    ctx.beginPath();
    ctx.rect(x - r, y - r * 0.6, r * 2, r * 1.6);
    ctx.arc(x, y - r * 0.6, r * 0.45, Math.PI, 0);
    ctx.arc(x + r, y + r * 0.2, r * 0.45, -Math.PI / 2, Math.PI / 2);
    ctx.fill();
}

createDriver({
    name:  'puzzle',
    Sim:   PuzzleSim,
    pointer: true,
    open:  true,
    startScreen: false,
    icon:  icon,
    render: function () {},
    controls: puzzleTable,
    /** Все ячейки заняты — ответ готов; верно ли, решит сервер. */
    ready: function (sim) { return sim.filled() === sim.n; },
    progress: function (sim) { return [sim.filled(), sim.n]; },
});
