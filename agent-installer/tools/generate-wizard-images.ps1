<#
.SYNOPSIS
  Membuat gambar wizard installer SIMA Asset Agent (bermerek AKP) dalam tiga ukuran DPI, untuk
  WizardImageFile dan WizardSmallImageFile di SIMA-Asset-Agent.iss. Gambar sengaja NETRAL BAHASA
  (hanya logo dan nama), sehingga satu set gambar dipakai ketiga bahasa installer.
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File generate-wizard-images.ps1
#>
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Join-Path $PSScriptRoot '..'
$logoPath = Join-Path $root '..\public\img\AKP.png'
$out = Join-Path $root 'assets'
$logo = [System.Drawing.Image]::FromFile((Resolve-Path $logoPath).Path)

function Color([string]$hex, [int]$alpha = 255) {
    $c = [System.Drawing.ColorTranslator]::FromHtml($hex)
    [System.Drawing.Color]::FromArgb($alpha, $c.R, $c.G, $c.B)
}

function New-Canvas([int]$w, [int]$h) {
    $bmp = New-Object System.Drawing.Bitmap $w, $h, ([System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
    $bmp.SetResolution(96, 96)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = 'AntiAlias'
    $g.InterpolationMode = 'HighQualityBicubic'
    $g.TextRenderingHint = 'AntiAliasGridFit'
    $g.PixelOffsetMode = 'HighQuality'
    return @{ Bitmap = $bmp; G = $g }
}

function Round-Rect([System.Drawing.Graphics]$g, $brush, [float]$x, [float]$y, [float]$w, [float]$h, [float]$r) {
    $p = New-Object System.Drawing.Drawing2D.GraphicsPath
    $p.AddArc($x, $y, 2 * $r, 2 * $r, 180, 90)
    $p.AddArc($x + $w - 2 * $r, $y, 2 * $r, 2 * $r, 270, 90)
    $p.AddArc($x + $w - 2 * $r, $y + $h - 2 * $r, 2 * $r, 2 * $r, 0, 90)
    $p.AddArc($x, $y + $h - 2 * $r, 2 * $r, 2 * $r, 90, 90)
    $p.CloseFigure()
    $g.FillPath($brush, $p)
    $p.Dispose()
}

# ---------- gambar samping besar (basis 164 x 314 px @96 DPI) ----------
function New-SideImage([int]$width) {
    $s = $width / 164.0
    $height = [int][math]::Round(314 * $s)
    $c = New-Canvas $width $height
    $g = $c.G

    $rect = New-Object System.Drawing.RectangleF 0, 0, $width, $height
    $grad = New-Object System.Drawing.Drawing2D.LinearGradientBrush $rect, (Color '#16224A'), (Color '#2E4285'), 70
    $g.FillRectangle($grad, $rect)

    # bentuk dekoratif berwarna merek (biru muda dan hijau toska) dengan transparansi
    $g.FillEllipse((New-Object System.Drawing.SolidBrush (Color '#4FA6DC' 55)), (100 * $s), (-50 * $s), (130 * $s), (130 * $s))
    $g.FillEllipse((New-Object System.Drawing.SolidBrush (Color '#66BFB0' 45)), (-55 * $s), (225 * $s), (130 * $s), (130 * $s))
    $ring = New-Object System.Drawing.Pen (Color '#FFFFFF' 28), (1.2 * $s)
    $g.DrawEllipse($ring, (92 * $s), (200 * $s), (96 * $s), (96 * $s))

    # pelat putih berisi logo AKP, dengan bayangan lembut
    $px = 14 * $s; $py = 46 * $s; $pw = 136 * $s; $ph = 54 * $s
    for ($i = 5; $i -ge 1; $i--) {
        $sh = New-Object System.Drawing.SolidBrush (Color '#000000' (9 * (6 - $i)))
        Round-Rect $g $sh ($px - $i * $s * 0.6) ($py + $i * $s * 1.3) ($pw + $i * $s * 1.2) $ph (12 * $s)
    }
    Round-Rect $g ([System.Drawing.Brushes]::White) $px $py $pw $ph (12 * $s)
    $pad = 9 * $s
    $lw = $pw - 2 * $pad
    $lh = $lw * $logo.Height / $logo.Width
    $g.DrawImage($logo, $px + $pad, $py + ($ph - $lh) / 2, $lw, $lh)

    # nama produk
    $fmt = New-Object System.Drawing.StringFormat
    $fmt.Alignment = 'Center'
    $big = New-Object System.Drawing.Font 'Segoe UI Semibold', (27 * $s), ([System.Drawing.FontStyle]::Regular), ([System.Drawing.GraphicsUnit]::Pixel)
    $g.DrawString('SIMA', $big, [System.Drawing.Brushes]::White, (New-Object System.Drawing.RectangleF 0, (128 * $s), $width, (40 * $s)), $fmt)
    $bar = New-Object System.Drawing.SolidBrush (Color '#4FA6DC')
    Round-Rect $g $bar ($width / 2 - 14 * $s) (172 * $s) (28 * $s) (3 * $s) (1.5 * $s)
    $mid = New-Object System.Drawing.Font 'Segoe UI', (13 * $s), ([System.Drawing.FontStyle]::Regular), ([System.Drawing.GraphicsUnit]::Pixel)
    $g.DrawString('Asset Agent', $mid, (New-Object System.Drawing.SolidBrush (Color '#D5DEF3')), (New-Object System.Drawing.RectangleF 0, (182 * $s), $width, (22 * $s)), $fmt)

    # kaki
    $small = New-Object System.Drawing.Font 'Segoe UI', (9 * $s), ([System.Drawing.FontStyle]::Regular), ([System.Drawing.GraphicsUnit]::Pixel)
    $g.DrawString('PT Adhi Kartiko Pratama Tbk', $small, (New-Object System.Drawing.SolidBrush (Color '#9FB1DB')), (New-Object System.Drawing.RectangleF 0, (284 * $s), $width, (18 * $s)), $fmt)

    $g.Dispose()
    return $c.Bitmap
}

# ---------- gambar kecil di pojok kanan atas halaman (basis 55 x 58 px) ----------
function New-SmallImage([int]$width) {
    $s = $width / 55.0
    $height = [int][math]::Round(58 * $s)
    $c = New-Canvas $width $height
    $g = $c.G
    $g.Clear([System.Drawing.Color]::White)

    $rect = New-Object System.Drawing.RectangleF 0, 0, ($width - 4 * $s), ($height - 4 * $s)
    $grad = New-Object System.Drawing.Drawing2D.LinearGradientBrush $rect, (Color '#2E4285'), (Color '#16224A'), 60
    $p = New-Object System.Drawing.Drawing2D.GraphicsPath
    $r = 9 * $s; $x = 2 * $s; $y = 2 * $s; $w = $width - 4 * $s; $h = $height - 4 * $s
    $p.AddArc($x, $y, 2 * $r, 2 * $r, 180, 90); $p.AddArc($x + $w - 2 * $r, $y, 2 * $r, 2 * $r, 270, 90)
    $p.AddArc($x + $w - 2 * $r, $y + $h - 2 * $r, 2 * $r, 2 * $r, 0, 90); $p.AddArc($x, $y + $h - 2 * $r, 2 * $r, 2 * $r, 90, 90)
    $p.CloseFigure()
    $g.FillPath($grad, $p)
    $g.SetClip($p)
    $g.FillEllipse((New-Object System.Drawing.SolidBrush (Color '#4FA6DC' 80)), (28 * $s), (-14 * $s), (36 * $s), (36 * $s))
    $g.FillEllipse((New-Object System.Drawing.SolidBrush (Color '#66BFB0' 70)), (-12 * $s), (36 * $s), (34 * $s), (34 * $s))
    $g.ResetClip()

    $fmt = New-Object System.Drawing.StringFormat
    $fmt.Alignment = 'Center'; $fmt.LineAlignment = 'Center'
    $f = New-Object System.Drawing.Font 'Segoe UI Semibold', (13 * $s), ([System.Drawing.FontStyle]::Regular), ([System.Drawing.GraphicsUnit]::Pixel)
    $g.DrawString('SIMA', $f, [System.Drawing.Brushes]::White, (New-Object System.Drawing.RectangleF 0, (2 * $s), $width, ($height - 4 * $s)), $fmt)
    $g.Dispose()
    return $c.Bitmap
}

New-Item -ItemType Directory -Force $out | Out-Null
foreach ($w in 164, 246, 328) {
    $b = New-SideImage $w
    $b.Save((Join-Path $out "wizard-$w.bmp"), [System.Drawing.Imaging.ImageFormat]::Bmp)
    $b.Dispose()
}
foreach ($w in 55, 83, 110) {
    $b = New-SmallImage $w
    $b.Save((Join-Path $out "wizard-small-$w.bmp"), [System.Drawing.Imaging.ImageFormat]::Bmp)
    $b.Dispose()
}
$logo.Dispose()
Write-Host 'Gambar wizard dibuat di' (Resolve-Path $out).Path -ForegroundColor Green
