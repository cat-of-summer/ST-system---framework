import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cellAt } from '../../Puzzle/src/board.js';

// Форма кусочков генерируется и вырезается на сервере (Image\Jigsaw, его тесты — в PHPUnit);
// клиенту остаётся только понять, над какой ячейкой поля отпустили кусочек.

test('ячейка под точкой', function () {
    assert.equal(cellAt(0, 0, 3, 3, 300, 300), 0);
    assert.equal(cellAt(150, 150, 3, 3, 300, 300), 4);
    assert.equal(cellAt(299, 299, 3, 3, 300, 300), 8);
    assert.equal(cellAt(250, 50, 3, 4, 400, 300), 2);
    assert.equal(cellAt(-1, 10, 3, 3, 300, 300), -1);
    assert.equal(cellAt(10, 300, 3, 3, 300, 300), -1);
});
