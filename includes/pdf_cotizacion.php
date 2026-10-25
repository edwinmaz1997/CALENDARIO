<?php
/**
 * Generacion del PDF de cotizacion con FPDF.
 */
require_once APP_ROOT . '/lib/fpdf/fpdf.php';

class CotizacionPDF extends FPDF
{
    public $emp;
    public $numero;
    public $c1;
    public $c2;
    public $c1txt;
    public $c2txt;

    public function __construct($emp, $numero)
    {
        parent::__construct('P', 'mm', 'Letter');
        $this->emp = $emp;
        $this->numero = $numero;
        $this->c1 = hex2rgb($emp['color_primario']);
        $this->c2 = hex2rgb($emp['color_secundario']);
        $this->c1txt = hex2rgb(color_texto($emp['color_primario']));
        $this->c2txt = hex2rgb(color_texto($emp['color_secundario']));
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 22);
        $this->AliasNbPages();
        $this->SetTitle(pdf_txt('Cotización ' . $numero), false);
        $this->SetAuthor(pdf_txt($emp['nombre']), false);
        $this->SetCreator('Cotizador');
    }

    public function Header()
    {
        // Franja superior con los colores de la empresa
        $this->SetFillColor($this->c1[0], $this->c1[1], $this->c1[2]);
        $this->Rect(0, 0, $this->GetPageWidth() * 0.7, 4, 'F');
        $this->SetFillColor($this->c2[0], $this->c2[1], $this->c2[2]);
        $this->Rect($this->GetPageWidth() * 0.7, 0, $this->GetPageWidth() * 0.3, 4, 'F');

        if ($this->PageNo() > 1) {
            $this->SetY(9);
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 5, pdf_txt($this->emp['nombre']), 0, 0, 'L');
            $this->Cell(0, 5, pdf_txt('Cotización ' . $this->numero), 0, 1, 'R');
            $this->Ln(4);
        }
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetDrawColor($this->c1[0], $this->c1[1], $this->c1[2]);
        $this->SetLineWidth(0.4);
        $this->Line(15, $this->GetY(), $this->GetPageWidth() - 15, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $contacto = implode('   |   ', array_filter([$this->emp['telefono'], $this->emp['correo'], $this->emp['sitio_web']]));
        $this->Cell(0, 4, pdf_txt($contacto), 0, 0, 'L');
        $this->Cell(0, 4, pdf_txt('Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'R');
    }

    /** Numero de lineas que ocupara un MultiCell de ancho $w */
    public function NbLines($w, $txt)
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c === ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 500;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}

/**
 * Construye el PDF y devuelve el objeto listo para Output().
 */
function construir_pdf_cotizacion($cot, $emp, $cliente, $lineas)
{
    $numero = numero_cotizacion($cot, $emp);
    $pdf = new CotizacionPDF($emp, $numero);
    $pdf->AddPage();
    $anchoUtil = $pdf->GetPageWidth() - 30;
    $c1 = $pdf->c1;
    $c2 = $pdf->c2;

    /* ---------- Encabezado: logo + datos de empresa ---------- */
    $yIni = 13;
    $xTexto = 15;
    $logo = logo_path($emp);
    if ($logo) {
        $info = @getimagesize($logo);
        if ($info && $info[0] > 0 && $info[1] > 0) {
            $maxW = 38;
            $maxH = 26;
            $ratio = $info[0] / $info[1];
            $w = $maxW;
            $h = $w / $ratio;
            if ($h > $maxH) {
                $h = $maxH;
                $w = $h * $ratio;
            }
            try {
                $pdf->Image($logo, 15, $yIni, $w, $h);
                $xTexto = 15 + $w + 5;
            } catch (Exception $ex) {
                // Si el logo no se puede leer se omite
            }
        }
    }

    $anchoEmpresa = 125 - ($xTexto - 15);
    $pdf->SetXY($xTexto, $yIni);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->SetTextColor($c1[0], $c1[1], $c1[2]);
    $pdf->MultiCell($anchoEmpresa, 6, pdf_txt($emp['nombre']), 0, 'L');
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    foreach ([
        $emp['nit'] ? 'NIT: ' . $emp['nit'] : null,
        $emp['direccion'],
        implode('  ·  ', array_filter([$emp['telefono'], $emp['correo']])),
        $emp['sitio_web'],
    ] as $lin) {
        if ($lin) {
            $pdf->SetX($xTexto);
            $pdf->MultiCell($anchoEmpresa, 4.2, pdf_txt($lin), 0, 'L');
        }
    }
    $yFinEmpresa = $pdf->GetY();

    // Recuadro con numero de cotizacion
    $xBox = 15 + $anchoUtil - 55;
    $pdf->SetFillColor($c1[0], $c1[1], $c1[2]);
    $pdf->SetTextColor($pdf->c1txt[0], $pdf->c1txt[1], $pdf->c1txt[2]);
    $pdf->SetXY($xBox, $yIni);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(55, 7, pdf_txt('COTIZACIÓN'), 0, 2, 'C', true);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell(55, 8, pdf_txt($numero), 0, 2, 'C', true);
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->Cell(55, 8, pdf_txt('Fecha: ' . fecha($cot['fecha'])), 'LRB', 2, 'C', true);
    $yFinBox = $pdf->GetY();

    $y = max($yFinEmpresa, $yFinBox, $yIni + 26) + 7;

    /* ---------- Cliente y evento ---------- */
    $colW = ($anchoUtil - 6) / 2;
    $bloque = function ($x, $titulo, $lineasTxt) use ($pdf, $colW, $c1, &$y) {
        $pdf->SetXY($x, $y);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetTextColor($c1[0], $c1[1], $c1[2]);
        $pdf->Cell($colW, 5, pdf_txt(mb_strtoupper($titulo)), 0, 2);
        $pdf->SetDrawColor($c1[0], $c1[1], $c1[2]);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($x, $pdf->GetY(), $x + 18, $pdf->GetY());
        $pdf->Ln(1.5);
        $primero = true;
        foreach ($lineasTxt as $t) {
            if ($t === null || $t === '') {
                continue;
            }
            $pdf->SetX($x);
            $pdf->SetFont('Helvetica', $primero ? 'B' : '', $primero ? 10 : 8.5);
            $pdf->SetTextColor($primero ? 15 : 71, $primero ? 23 : 85, $primero ? 42 : 105);
            $pdf->MultiCell($colW, $primero ? 5.2 : 4.3, pdf_txt($t), 0, 'L');
            $primero = false;
        }
        return $pdf->GetY();
    };
    $y1 = $bloque(15, 'Cliente', [
        $cliente['nombre'],
        'NIT: ' . ($cliente['nit'] ?: 'CF'),
        $cliente['telefono'] ? 'Tel: ' . $cliente['telefono'] : null,
        $cliente['correo'],
        $cliente['direccion'],
    ]);
    $y2 = $bloque(15 + $colW + 6, 'Datos del evento', [
        ucfirst(fecha_larga($cot['fecha_evento'])) . ($cot['hora_evento'] ? ', ' . hora($cot['hora_evento']) : ''),
        $cot['lugar_evento'] ? 'Lugar: ' . $cot['lugar_evento'] : null,
    ]);
    $pdf->SetY(max($y1, $y2) + 7);

    /* ---------- Tabla de detalle ---------- */
    $cols = [
        ['#', 9, 'C'],
        ['Descripción', $anchoUtil - 9 - 22 - 30 - 32, 'L'],
        ['Cantidad', 22, 'R'],
        ['Precio unit.', 30, 'R'],
        ['Subtotal', 32, 'R'],
    ];
    $encabezado = function () use ($pdf, $cols, $c1) {
        $pdf->SetFillColor($c1[0], $c1[1], $c1[2]);
        $pdf->SetTextColor($pdf->c1txt[0], $pdf->c1txt[1], $pdf->c1txt[2]);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        foreach ($cols as $c) {
            $pdf->Cell($c[1], 8, pdf_txt($c[0]), 0, 0, $c[2] === 'L' ? 'L' : $c[2], true);
        }
        $pdf->Ln();
    };
    $encabezado();

    $moneda = $emp['moneda'];
    $fila = 0;
    foreach ($lineas as $i => $l) {
        $pdf->SetFont('Helvetica', '', 9);
        $nl = $pdf->NbLines($cols[1][1], pdf_txt($l['descripcion']));
        $h = max(8, $nl * 4.6 + ($l['unidad'] ? 4 : 0) + 3);

        if ($pdf->GetY() + $h > $pdf->GetPageHeight() - 24) {
            $pdf->AddPage();
            $encabezado();
        }
        $x0 = $pdf->GetX();
        $y0 = $pdf->GetY();
        if ($fila % 2 === 1) {
            $pdf->SetFillColor(248, 250, 252);
            $pdf->Rect($x0, $y0, $anchoUtil, $h, 'F');
        }
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell($cols[0][1], $h, (string)($i + 1), 0, 0, 'C');

        $xDesc = $pdf->GetX();
        $pdf->SetXY($xDesc, $y0 + 1.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->MultiCell($cols[1][1], 4.6, pdf_txt($l['descripcion']), 0, 'L');
        if ($l['unidad']) {
            $pdf->SetX($xDesc);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($cols[1][1], 4, pdf_txt($l['unidad']), 0, 0, 'L');
        }

        $pdf->SetXY($xDesc + $cols[1][1], $y0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(51, 65, 85);
        $cant = rtrim(rtrim(number_format((float)$l['cantidad'], 2, '.', ','), '0'), '.');
        $pdf->Cell($cols[2][1], $h, $cant, 0, 0, 'R');
        $pdf->Cell($cols[3][1], $h, pdf_txt(money($l['precio_unitario'], $moneda)), 0, 0, 'R');
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell($cols[4][1], $h, pdf_txt(money($l['subtotal'], $moneda)), 0, 0, 'R');

        $pdf->SetDrawColor(226, 232, 240);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($x0, $y0 + $h, $x0 + $anchoUtil, $y0 + $h);
        $pdf->SetXY($x0, $y0 + $h);
        $fila++;
    }

    /* ---------- Total ---------- */
    if ($pdf->GetY() + 16 > $pdf->GetPageHeight() - 24) {
        $pdf->AddPage();
    }
    $pdf->Ln(4);
    $wTot = 75;
    $pdf->SetX(15 + $anchoUtil - $wTot);
    $pdf->SetFillColor($c2[0], $c2[1], $c2[2]);
    $pdf->SetTextColor($pdf->c2txt[0], $pdf->c2txt[1], $pdf->c2txt[2]);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(28, 11, 'TOTAL', 0, 0, 'L', true);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell($wTot - 28, 11, pdf_txt(money($cot['total'], $moneda)), 0, 1, 'R', true);

    /* ---------- Pagos recibidos ---------- */
    $pagos = db_all('SELECT fecha, descripcion, metodo, monto FROM cotizacion_pagos WHERE cotizacion_id = ? ORDER BY fecha, id', [$cot['id']]);
    if ($pagos) {
        $pagado = 0;
        foreach ($pagos as $pg) {
            $pagado += (float)$pg['monto'];
        }
        $saldo = round((float)$cot['total'] - $pagado, 2);
        $alto = 8 + count($pagos) * 5.5 + 16;
        if ($pdf->GetY() + $alto > $pdf->GetPageHeight() - 24) {
            $pdf->AddPage();
        }
        $pdf->Ln(6);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetTextColor($c1[0], $c1[1], $c1[2]);
        $pdf->Cell(0, 5, pdf_txt('PAGOS RECIBIDOS'), 0, 1);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->SetLineWidth(0.2);
        foreach ($pagos as $pg) {
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell(22, 5.5, fecha($pg['fecha']), 'B', 0, 'L');
            $pdf->SetTextColor(15, 23, 42);
            $txt = $pg['descripcion'] . ($pg['metodo'] ? ' (' . $pg['metodo'] . ')' : '');
            $pdf->Cell($anchoUtil - 22 - 35, 5.5, pdf_txt(mb_strimwidth($txt, 0, 90, '...')), 'B', 0, 'L');
            $pdf->SetFont('Helvetica', 'B', 8.5);
            $pdf->Cell(35, 5.5, pdf_txt(money($pg['monto'], $moneda)), 'B', 1, 'R');
        }
        $pdf->Ln(2);
        $pdf->SetX(15 + $anchoUtil - $wTot);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(40, 6, pdf_txt('Total pagado'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pdf->SetTextColor(5, 150, 105);
        $pdf->Cell($wTot - 40, 6, pdf_txt(money($pagado, $moneda)), 0, 1, 'R');
        $pdf->SetX(15 + $anchoUtil - $wTot);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(40, 7, pdf_txt($saldo > 0 ? 'Saldo pendiente' : 'Saldo'), 'T', 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 11);
        if ($saldo > 0) {
            $pdf->SetTextColor(225, 29, 72);
        } else {
            $pdf->SetTextColor(5, 150, 105);
        }
        $pdf->Cell($wTot - 40, 7, pdf_txt($saldo > 0 ? money($saldo, $moneda) : 'PAGADO'), 'T', 1, 'R');
    }

    /* ---------- Notas y pie ---------- */
    $textoBloque = function ($titulo, $texto, $tam) use ($pdf, $c1, $anchoUtil) {
        if (trim((string)$texto) === '') {
            return;
        }
        $pdf->SetFont('Helvetica', '', $tam);
        $alto = $pdf->NbLines($anchoUtil, pdf_txt($texto)) * 4.3 + 9;
        if ($pdf->GetY() + $alto > $pdf->GetPageHeight() - 24) {
            $pdf->AddPage();
        }
        $pdf->Ln(6);
        if ($titulo) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->SetTextColor($c1[0], $c1[1], $c1[2]);
            $pdf->Cell(0, 5, pdf_txt(mb_strtoupper($titulo)), 0, 1);
        }
        $pdf->SetFont('Helvetica', '', $tam);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell($anchoUtil, 4.3, pdf_txt($texto), 0, 'L');
    };
    /* ---------- Imagen de la cotizacion ---------- */
    if (!empty($cot['imagen'])) {
        require_once APP_ROOT . '/includes/imagen_cotizacion.php';
        $rutaImg = imagen_cot_path($cot['empresa_id'], $cot['imagen']);
        $infoImg = $rutaImg ? @getimagesize($rutaImg) : null;
        if ($infoImg && $infoImg[0] > 0 && $infoImg[1] > 0) {
            $maxW = $anchoUtil;
            $maxH = 90;
            $ratio = $infoImg[0] / $infoImg[1];
            $w = $maxW;
            $h = $w / $ratio;
            if ($h > $maxH) {
                $h = $maxH;
                $w = $h * $ratio;
            }
            if ($pdf->GetY() + $h + 8 > $pdf->GetPageHeight() - 24) {
                $pdf->AddPage();
            }
            $pdf->Ln(6);
            try {
                $pdf->Image($rutaImg, 15 + ($anchoUtil - $w) / 2, $pdf->GetY(), $w, $h);
                $pdf->SetY($pdf->GetY() + $h);
            } catch (Exception $ex) {
                // Imagen en formato no soportado por FPDF: se omite
            }
        }
    }

    $textoBloque('Notas', $cot['notas'], 9);
    $textoBloque('Condiciones', $emp['pie_cotizacion'], 8);

    return $pdf;
}

/**
 * Envia el PDF al navegador SIN cache (siempre la version actual de la cotizacion).
 * FPDF::Output('I') pone sus propios encabezados de cache, por eso se envia a mano.
 */
function enviar_pdf_sin_cache($pdf, $nombre, $inline = true)
{
    $bin = $pdf->Output('S');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombre);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bin));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    echo $bin;
    exit;
}

