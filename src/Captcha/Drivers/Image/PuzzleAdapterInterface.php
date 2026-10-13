<?php

namespace ST_system\Captcha\Drivers\Image;

/**
 * Нарезка картинки для PuzzleCaptchaDriver. Отдельно от ImageAdapterInterface, чтобы не ломать
 * сторонние адаптеры текстовой капчи.
 */
interface PuzzleAdapterInterface {

    public static function isAvailable(): bool;

    /**
     * Спрайт кусочков.
     *
     * Картинка $file кадрируется под width × height (cover со случайным масштабом и сдвигом), чуть
     * меняется яркость и контраст, вокруг добавляется зеркальное поле шириной bleed. Затем она
     * режется на rows × cols кусочков с полем bleed с каждой стороны, и кусочки укладываются в
     * спрайт той же сетки: в ячейке k — кусочек order[k].
     *
     * outlines (Jigsaw::outlines()) — контур каждого кусочка в координатах его ячейки: всё вне
     * контура прозрачное, формат — WebP с альфой (или PNG). Без outlines ячейки прямоугольные, JPEG.
     *
     * @param array{width:int,height:int,rows:int,cols:int,bleed:int,order:int[],outlines?:array,quality?:int} $options
     */
    public static function puzzle(string $file, array $options): string;
}
