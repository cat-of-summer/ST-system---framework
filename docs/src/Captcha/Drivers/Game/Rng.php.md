<!-- DOCGEN:START -->
# Rng.php
<!-- DOCGEN:END -->

`ST_system\Captcha\Drivers\Game\Rng` - генератор xorshift32, общий для PHP- и JS-копий игр
(`assets/engine/src/rng.js`). Умножения нет намеренно: в JS оно теряет точность за 2^53, а сдвиги и XOR
в обоих языках дают одинаковые 32 бита. Нулевой seed заменяется константой `0x9E3779B9`.

`next()` - следующее 32-битное число, `int($n)` - целое `0..n-1`. Требует 64-битного PHP.
