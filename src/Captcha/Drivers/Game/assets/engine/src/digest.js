// Только для тестов: в браузерный бандл не попадает.

const TABLE = (() => {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
        t[n] = c >>> 0;
    }
    return t;
})();

/** crc32 строки ASCII — то же, что crc32() в PHP. */
export function crc32(str) {
    let c = 0xFFFFFFFF;
    for (let i = 0; i < str.length; i++) c = TABLE[(c ^ str.charCodeAt(i)) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
}

export function digest(sim) {
    return crc32(sim.state().join(','));
}
