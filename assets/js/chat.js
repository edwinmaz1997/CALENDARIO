/* Burbuja de chat (misma estructura que burbuja_chat() en PHP). */
window.burbujaChat = function (m, yo, nombreCliente, nombreEmpresa) {
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  if (m.autor === 'sistema') return '<div class="flex justify-center py-1"><p class="max-w-[90%] rounded-full bg-slate-200/80 px-3 py-1 text-center text-[11px] font-semibold text-slate-600">' + esc(m.mensaje) + ' · ' + esc(m.cuando) + '</p></div>';
  var mio = m.autor === yo, nombre = m.autor === 'cliente' ? nombreCliente : nombreEmpresa;
  var etiqueta = mio ? 'Tú' : nombre, inicial = (String(nombre).trim().charAt(0) || '?').toUpperCase();
  var nuevo = !mio && m.autor === 'cliente';
  return '<div class="flex items-start gap-2 ' + (mio ? 'flex-row-reverse' : '') + '">' +
    '<span class="mt-5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-extrabold ' + (mio ? 'burbuja-propia' : 'bg-slate-200 text-slate-600') + '">' + esc(inicial) + '</span>' +
    '<div class="min-w-0 max-w-[80%]"><p class="mb-0.5 px-1 text-[11px] font-bold ' + (mio ? 'text-right texto-marca' : 'text-slate-500') + '">' + esc(etiqueta) + '</p>' +
    '<div class="rounded-2xl px-3.5 py-2 text-sm shadow-sm ' + (mio ? 'burbuja-propia rounded-tr-md' : 'rounded-tl-md bg-white text-slate-800 ring-1 ' + (nuevo ? 'ring-rose-300' : 'ring-slate-200')) + '">' +
    '<p class="whitespace-pre-line break-words">' + esc(m.mensaje) + '</p><p class="mt-1 text-right text-[10px] ' + (mio ? 'opacity-75' : 'text-slate-400') + '">' + esc(m.cuando) + '</p></div></div></div>';
};
