// Código QR sin dependencias (ISO/IEC 18004): modo byte, corrección Q (aguanta un 25 % del código sucio o tapado) y
// versiones 1 a 6, que bastan para una dirección de Bide+. Sigue el algoritmo de referencia de Project Nayuki.
// qrMatrix(texto) da las filas de módulos (true = negro); qrSvg(texto) lo dibuja con el margen de 4 módulos.

const ECC_PER_BLOCK = [0, 13, 22, 18, 26, 18, 24];
const BLOCKS = [0, 1, 1, 2, 2, 4, 4];
const FORMAT_LEVEL_Q = 3;

function rawDataModules(iVersion) {
    let iResult = (16 * iVersion + 128) * iVersion + 64;
    if (iVersion >= 2) {
        const iAlign = Math.floor(iVersion / 7) + 2;
        iResult -= (25 * iAlign - 10) * iAlign - 55;
    }
    return iResult;
}

const dataCodewords = (iVersion) => Math.floor(rawDataModules(iVersion) / 8) - ECC_PER_BLOCK[iVersion] * BLOCKS[iVersion];

function gfMultiply(iX, iY) {
    let iZ = 0;
    for (let i = 7; i >= 0; i--) {
        iZ = (iZ << 1) ^ ((iZ >>> 7) * 0x11d);
        iZ ^= ((iY >>> i) & 1) * iX;
    }
    return iZ;
}

function rsDivisor(iDegree) {
    const aResult = new Array(iDegree).fill(0);
    aResult[iDegree - 1] = 1;
    let iRoot = 1;
    for (let i = 0; i < iDegree; i++) {
        for (let j = 0; j < aResult.length; j++) {
            aResult[j] = gfMultiply(aResult[j], iRoot);
            if (j + 1 < aResult.length) {
                aResult[j] ^= aResult[j + 1];
            }
        }
        iRoot = gfMultiply(iRoot, 0x02);
    }
    return aResult;
}

function rsRemainder(aData, aDivisor) {
    const aResult = aDivisor.map(() => 0);
    for (const iByte of aData) {
        const iFactor = iByte ^ aResult.shift();
        aResult.push(0);
        aDivisor.forEach((iCoef, i) => (aResult[i] ^= gfMultiply(iCoef, iFactor)));
    }
    return aResult;
}

function codewords(sText) {
    const aBytes = [...new TextEncoder().encode(sText)];
    const iVersion = [1, 2, 3, 4, 5, 6].find((v) => 4 + 8 + aBytes.length * 8 <= dataCodewords(v) * 8);
    if (!iVersion) {
        throw new Error(`Texto demasiado largo para el QR: ${sText}`);
    }
    const aBits = [];
    const push = (iValue, iLength) => {
        for (let i = iLength - 1; i >= 0; i--) {
            aBits.push((iValue >>> i) & 1);
        }
    };
    push(0b0100, 4);
    push(aBytes.length, 8);
    aBytes.forEach((b) => push(b, 8));
    const iCapacity = dataCodewords(iVersion) * 8;
    push(0, Math.min(4, iCapacity - aBits.length));
    push(0, (8 - (aBits.length % 8)) % 8);
    const aData = [];
    for (let i = 0; i < aBits.length; i += 8) {
        aData.push(parseInt(aBits.slice(i, i + 8).join(''), 2));
    }
    for (let iPad = 0xec; aData.length < iCapacity / 8; iPad ^= 0xec ^ 0x11) {
        aData.push(iPad);
    }

    // Bloques con su corrección, entrelazados.
    const iBlocks = BLOCKS[iVersion];
    const iEcc = ECC_PER_BLOCK[iVersion];
    const iRaw = Math.floor(rawDataModules(iVersion) / 8);
    const iShortBlocks = iBlocks - (iRaw % iBlocks);
    const iShortLength = Math.floor(iRaw / iBlocks);
    const aDivisor = rsDivisor(iEcc);
    const aBlocks = [];
    for (let i = 0, k = 0; i < iBlocks; i++) {
        let iLength = iShortLength - iEcc;
        if (i >= iShortBlocks) {
            iLength++;
        }
        const aChunk = aData.slice(k, k + iLength);
        k += aChunk.length;
        const aCorrection = rsRemainder(aChunk, aDivisor);
        if (i < iShortBlocks) {
            aChunk.push(0);
        }
        aBlocks.push(aChunk.concat(aCorrection));
    }
    const aResult = [];
    for (let i = 0; i < aBlocks[0].length; i++) {
        aBlocks.forEach((aBlock, j) => {
            if (i !== iShortLength - iEcc || j >= iShortBlocks) {
                aResult.push(aBlock[i]);
            }
        });
    }
    return { iVersion, aCodewords: aResult };
}

const MASKS = [
    (x, y) => (x + y) % 2 === 0,
    (x, y) => y % 2 === 0,
    (x) => x % 3 === 0,
    (x, y) => (x + y) % 3 === 0,
    (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
    (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
    (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
    (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
];

function build(iVersion, aCodewords, iMask) {
    const iSize = iVersion * 4 + 17;
    const aModules = Array.from({ length: iSize }, () => new Array(iSize).fill(false));
    const aFunction = Array.from({ length: iSize }, () => new Array(iSize).fill(false));
    const set = (x, y, bDark) => {
        aModules[y][x] = bDark;
        aFunction[y][x] = true;
    };
    for (let i = 0; i < iSize; i++) {
        set(6, i, i % 2 === 0);
        set(i, 6, i % 2 === 0);
    }
    for (const [cx, cy] of [[3, 3], [iSize - 4, 3], [3, iSize - 4]]) {
        for (let dy = -4; dy <= 4; dy++) {
            for (let dx = -4; dx <= 4; dx++) {
                const x = cx + dx;
                const y = cy + dy;
                if (x >= 0 && x < iSize && y >= 0 && y < iSize) {
                    const iDistance = Math.max(Math.abs(dx), Math.abs(dy));
                    set(x, y, iDistance !== 2 && iDistance !== 4);
                }
            }
        }
    }
    if (iVersion >= 2) {
        // Versiones 2 a 6: un solo patrón de alineación, en (6, 6) y la otra esquina.
        const iAt = iSize - 7;
        for (let dy = -2; dy <= 2; dy++) {
            for (let dx = -2; dx <= 2; dx++) {
                set(iAt + dx, iAt + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
            }
        }
    }

    // Información de formato: nivel Q y máscara, con su BCH, en las dos copias.
    const iData = (FORMAT_LEVEL_Q << 3) | iMask;
    let iRemainder = iData;
    for (let i = 0; i < 10; i++) {
        iRemainder = (iRemainder << 1) ^ ((iRemainder >>> 9) * 0x537);
    }
    const iFormat = ((iData << 10) | iRemainder) ^ 0x5412;
    const bit = (i) => ((iFormat >>> i) & 1) === 1;
    for (let i = 0; i <= 5; i++) {
        set(8, i, bit(i));
    }
    set(8, 7, bit(6));
    set(8, 8, bit(7));
    set(7, 8, bit(8));
    for (let i = 9; i < 15; i++) {
        set(14 - i, 8, bit(i));
    }
    for (let i = 0; i < 8; i++) {
        set(iSize - 1 - i, 8, bit(i));
    }
    for (let i = 8; i < 15; i++) {
        set(8, iSize - 15 + i, bit(i));
    }
    set(8, iSize - 8, true);

    // Datos en zigzag, de dos en dos columnas desde la esquina de abajo a la derecha.
    let iBit = 0;
    for (let iRight = iSize - 1; iRight >= 1; iRight -= 2) {
        if (iRight === 6) {
            iRight = 5;
        }
        for (let iVert = 0; iVert < iSize; iVert++) {
            for (let j = 0; j < 2; j++) {
                const x = iRight - j;
                let y = iVert;
                if (((iRight + 1) & 2) === 0) {
                    y = iSize - 1 - iVert;
                }
                if (!aFunction[y][x] && iBit < aCodewords.length * 8) {
                    aModules[y][x] = ((aCodewords[iBit >>> 3] >>> (7 - (iBit & 7))) & 1) === 1;
                    iBit++;
                }
            }
        }
    }
    for (let y = 0; y < iSize; y++) {
        for (let x = 0; x < iSize; x++) {
            if (!aFunction[y][x] && MASKS[iMask](x, y)) {
                aModules[y][x] = !aModules[y][x];
            }
        }
    }
    return aModules;
}

// Penalización de la norma: se elige la máscara que deja el código más fácil de leer.
function penalty(aModules) {
    const iSize = aModules.length;
    let iScore = 0;
    const lines = [];
    for (let i = 0; i < iSize; i++) {
        lines.push(aModules[i]);
        lines.push(aModules.map((aRow) => aRow[i]));
    }
    for (const aLine of lines) {
        let iRun = 1;
        for (let i = 1; i <= iSize; i++) {
            if (i < iSize && aLine[i] === aLine[i - 1]) {
                iRun++;
                continue;
            }
            if (iRun >= 5) {
                iScore += iRun - 2;
            }
            iRun = 1;
        }
        const sLine = '0000' + aLine.map(Number).join('') + '0000';
        for (const sPattern of ['00001011101', '10111010000']) {
            for (let i = sLine.indexOf(sPattern); i !== -1; i = sLine.indexOf(sPattern, i + 1)) {
                iScore += 40;
            }
        }
    }
    for (let y = 0; y < iSize - 1; y++) {
        for (let x = 0; x < iSize - 1; x++) {
            const b = aModules[y][x];
            if (b === aModules[y][x + 1] && b === aModules[y + 1][x] && b === aModules[y + 1][x + 1]) {
                iScore += 3;
            }
        }
    }
    const iDark = aModules.flat().filter(Boolean).length;
    const iTotal = iSize * iSize;
    iScore += 10 * (Math.ceil(Math.abs(iDark * 20 - iTotal * 10) / iTotal) - 1);
    return iScore;
}

export function qrMatrix(sText) {
    const { iVersion, aCodewords } = codewords(sText);
    let aBest = null;
    let iBest = Infinity;
    for (let iMask = 0; iMask < 8; iMask++) {
        const aModules = build(iVersion, aCodewords, iMask);
        const iScore = penalty(aModules);
        if (iScore < iBest) {
            iBest = iScore;
            aBest = aModules;
        }
    }
    return aBest;
}

export function qrSvg(sText, sColor = '#000') {
    const aModules = qrMatrix(sText);
    const iSize = aModules.length + 8;
    let sPath = '';
    aModules.forEach((aRow, y) => aRow.forEach((bDark, x) => {
        if (bDark) {
            sPath += `M${x + 4} ${y + 4}h1v1h-1z`;
        }
    }));
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${iSize} ${iSize}" shape-rendering="crispEdges"><rect width="${iSize}" height="${iSize}" fill="#fff"/><path d="${sPath}" fill="${sColor}"/></svg>`;
}
