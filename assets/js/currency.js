/* Monetary display helpers. DB values are TOMAN; form values use selected display unit. */
(function (root) {
    'use strict';
    var code = (root.APP_CURRENCY === 'IRR') ? 'IRR' : 'TOMAN';
    var label = code === 'IRR' ? 'ریال' : 'تومان';
    function norm(raw) {
        if (raw === undefined || raw === null) throw Error('Invalid amount');
        var s = String(raw).trim().replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
            .replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
            .replace(/٬/g, ',').replace(/٫/g, '.');
        if (!/^[+-]?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?$/.test(s)) throw Error('Invalid amount');
        s = s.replace(/,/g, '');
        var neg = s[0] === '-';
        s = s.replace(/^[+-]/, '');
        var p = s.split('.');
        p[0] = p[0].replace(/^0+(?=\d)/, '');
        p[1] = (p[1] || '').replace(/0+$/, '');
        return (neg && !(p[0] === '0' && !p[1]) ? '-' : '') + p[0] + (p[1] ? '.' + p[1] : '');
    }
    function shift(raw, power) {
        var s = norm(raw), neg = s[0] === '-';
        if (neg) s = s.slice(1);
        var p = s.split('.'), digit = p.join(''), pos = p[0].length + power;
        if (pos <= 0) { digit = '0'.repeat(1 - pos) + digit; pos = 1; }
        if (pos >= digit.length) digit += '0'.repeat(pos - digit.length);
        return norm((neg ? '-' : '') + digit.slice(0, pos) + (pos < digit.length ? '.' + digit.slice(pos) : ''));
    }
    function fromBase(value) { return shift(value, code === 'IRR' ? 1 : 0); }
    function toBase(value) {
        var s = shift(value, code === 'IRR' ? -1 : 0);
        if ((s.split('.')[1] || '').length > 2) throw Error('Unrepresentable monetary value');
        return s;
    }
    function group(value) {
        var p = norm(value).split('.');
        return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (p.length > 1 ? '.' + p[1] : '');
    }
    root.Currency = {code: code, label: label, fromBase: fromBase, toBase: toBase,
        format: function (base) { return group(fromBase(base)) + ' ' + label; },
        formatNumber: function (base) { return group(fromBase(base)); },
        group: group};
}(window));
