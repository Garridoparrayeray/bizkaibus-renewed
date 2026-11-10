// Las direcciones web de los textos de los avisos (por ejemplo la de la huelga del consorcio) se pintan como enlaces que
// se abren aparte. Se arma con nodos de texto y no con HTML: un aviso nunca puede meter código en la página.
const BideLinks = (() => {
    const WEB_ADDRESS = /https?:\/\/[^\s<>"']+/g;
    const FINAL_PUNCTUATION = /[.,;:!?)\]]+$/;

    function isWebAddress(address) {
        try {
            const protocol = new URL(address).protocol;
            return protocol === 'https:' || protocol === 'http:';
        } catch (e) {
            return false;
        }
    }

    // El texto en trozos: { text } y, para cada dirección, { text, url } sin la puntuación con la que acaba la frase.
    function split(text) {
        const parts = [];
        let last = 0;
        for (const match of text.matchAll(WEB_ADDRESS)) {
            let address = match[0];
            const punctuation = address.match(FINAL_PUNCTUATION);
            if (punctuation) address = address.slice(0, address.length - punctuation[0].length);
            if (!isWebAddress(address)) continue;
            if (match.index > last) parts.push({ text: text.slice(last, match.index) });
            parts.push({ text: address, url: address });
            last = match.index + address.length;
        }
        if (last < text.length) parts.push({ text: text.slice(last) });
        return parts;
    }

    function append(node, text) {
        for (const part of split(text)) {
            if (!part.url) {
                node.append(document.createTextNode(part.text));
                continue;
            }
            const link = document.createElement('a');
            link.className = 'alert-link';
            link.href = part.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = part.text;
            node.append(link);
        }
    }

    return { split, append };
})();
