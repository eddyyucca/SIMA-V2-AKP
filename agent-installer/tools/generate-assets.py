"""
Membuat gambar wizard Inno Setup (BMP) + ikon .ico untuk installer SIMA Asset Agent.
Palet mengikuti tema SIMA yang sudah dibuat (public/akp/theme.css): navy -> sky.
Dijalankan sekali saat menyiapkan proyek installer; hasilnya di-commit, skrip ini tidak dijalankan oleh build.
"""
import math
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageFilter

ROOT = Path(r"C:\xampp\htdocs\akp_master\sima_akp")
LOGO_PATH = ROOT / "public" / "AKP.png"
OUT = Path(r"C:\xampp\htdocs\akp_master\sima_akp\agent-installer\assets")
OUT.mkdir(parents=True, exist_ok=True)

NAVY_TOP = (14, 28, 56)       # #0e1c38
NAVY_BOTTOM = (32, 56, 104)   # #203868
ACCENT = (40, 168, 224)       # #28a8e0 sky
TEAL = (56, 192, 176)         # #38c0b0

FONT_BOLD = r"C:\Windows\Fonts\segoeuib.ttf"
FONT_SEMI = r"C:\Windows\Fonts\segoeuisl.ttf"
FONT_REG = r"C:\Windows\Fonts\segoeui.ttf"


def vertical_gradient(size, top, bottom):
    w, h = size
    base = Image.new("RGB", (1, h), 0)
    for y in range(h):
        t = y / max(h - 1, 1)
        base.putpixel((0, y), tuple(int(top[i] + (bottom[i] - top[i]) * t) for i in range(3)))
    return base.resize((w, h))


def rounded_rect_mask(size, radius):
    mask = Image.new("L", size, 0)
    d = ImageDraw.Draw(mask)
    d.rounded_rectangle([0, 0, size[0] - 1, size[1] - 1], radius=radius, fill=255)
    return mask


def soft_shadow(size, radius, blur=14, offset=(0, 6), alpha=90):
    w, h = size
    canvas = Image.new("RGBA", (w + blur * 4, h + blur * 4 + offset[1]), (0, 0, 0, 0))
    shadow = Image.new("RGBA", (w, h), (0, 0, 0, alpha))
    mask = rounded_rect_mask((w, h), radius)
    canvas.paste(shadow, (blur * 2 + offset[0], blur * 2 + offset[1]), mask)
    canvas = canvas.filter(ImageFilter.GaussianBlur(blur))
    return canvas, (blur * 2, blur * 2)


def draw_accent_dots(draw, cx, cy, n=3, r0=3, gap=11, color=ACCENT):
    for i in range(n):
        draw.ellipse([cx - r0 - i * 0, cy + i * gap - r0, cx + r0, cy + i * gap + r0], fill=color)


def build_wizard_image():
    W, H = 328, 628
    img = vertical_gradient((W, H), NAVY_TOP, NAVY_BOTTOM).convert("RGBA")

    # aksen lingkaran transparan dekoratif pojok kanan atas (nuansa teal/sky, senada logo)
    deco = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    dd = ImageDraw.Draw(deco)
    dd.ellipse([W - 170, -110, W + 130, 190], fill=ACCENT + (28,))
    dd.ellipse([W - 90, -40, W + 160, 230], fill=TEAL + (22,))
    img = Image.alpha_composite(img, deco)

    # kartu putih berisi logo
    logo = Image.open(LOGO_PATH).convert("RGBA")
    card_w, card_h = 248, 108
    pad = 28
    logo_ratio = logo.width / logo.height
    target_w = card_w - pad * 2
    target_h = int(target_w / logo_ratio)
    if target_h > card_h - pad * 2:
        target_h = card_h - pad * 2
        target_w = int(target_h * logo_ratio)
    logo_resized = logo.resize((target_w, target_h), Image.LANCZOS)

    card_x, card_y = (W - card_w) // 2, 96
    shadow_img, shadow_off = soft_shadow((card_w, card_h), radius=18, blur=16, offset=(0, 8), alpha=110)
    img.alpha_composite(shadow_img, (card_x - shadow_off[0], card_y - shadow_off[1]))

    card = Image.new("RGBA", (card_w, card_h), (255, 255, 255, 255))
    card.putalpha(rounded_rect_mask((card_w, card_h), 18))
    img.alpha_composite(card, (card_x, card_y))
    logo_pos = (card_x + (card_w - target_w) // 2, card_y + (card_h - target_h) // 2)
    img.alpha_composite(logo_resized, logo_pos)

    draw = ImageDraw.Draw(img)

    # Judul
    title_font = ImageFont.truetype(FONT_BOLD, 34)
    subtitle_font = ImageFont.truetype(FONT_SEMI, 15)
    small_font = ImageFont.truetype(FONT_REG, 12)

    def center_text(y, text, font, fill, spacing=0):
        bbox = draw.textbbox((0, 0), text, font=font)
        w = bbox[2] - bbox[0]
        draw.text(((W - w) / 2, y), text, font=font, fill=fill)

    center_text(card_y + card_h + 34, "SIMA", title_font, (255, 255, 255))

    # garis aksen pendek di bawah judul
    line_y = card_y + card_h + 34 + 48
    draw.rounded_rectangle([(W - 46) / 2, line_y, (W + 46) / 2, line_y + 4], radius=2, fill=ACCENT)

    center_text(line_y + 16, "Asset Monitoring Agent", subtitle_font, (203, 221, 240))

    # keterangan singkat di bagian bawah
    lines = ["Memantau status online &", "info perangkat aset IT", "secara resmi dan transparan"]
    y = H - 118
    for line in lines:
        bbox = draw.textbbox((0, 0), line, font=small_font)
        w = bbox[2] - bbox[0]
        draw.text(((W - w) / 2, y), line, font=small_font, fill=(150, 170, 200))
        y += 18

    img.convert("RGB").save(OUT / "wizard-image.bmp", "BMP")
    print("wizard-image.bmp", img.size)


def build_wizard_small():
    S = 110
    img = vertical_gradient((S, S), NAVY_TOP, NAVY_BOTTOM).convert("RGBA")
    mask = rounded_rect_mask((S, S), 0)  # persegi penuh, tanpa sudut membulat (ikon area kecil)
    img.putalpha(255)

    logo = Image.open(LOGO_PATH).convert("RGBA")
    logo_ratio = logo.width / logo.height
    pad = 16
    target_w = S - pad * 2
    target_h = int(target_w / logo_ratio)
    if target_h > S - pad * 2:
        target_h = S - pad * 2
        target_w = int(target_h * logo_ratio)
    logo_resized = logo.resize((target_w, target_h), Image.LANCZOS)

    card_w, card_h = S - 14, target_h + 18
    card_x, card_y = (S - card_w) // 2, (S - card_h) // 2
    card = Image.new("RGBA", (card_w, card_h), (255, 255, 255, 255))
    card.putalpha(rounded_rect_mask((card_w, card_h), 10))
    img.alpha_composite(card, (card_x, card_y))
    img.alpha_composite(logo_resized, (card_x + (card_w - target_w) // 2, card_y + (card_h - target_h) // 2))

    img.convert("RGB").save(OUT / "wizard-small.bmp", "BMP")
    print("wizard-small.bmp", img.size)


def build_icon():
    """Ikon aplikasi: lingkaran navy dengan mark 'A' (segitiga biru/teal, diambil dari logo)."""
    sizes = [16, 24, 32, 48, 64, 128, 256]
    base = 256
    img = Image.new("RGBA", (base, base), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    # lingkaran gradasi navy
    grad = vertical_gradient((base, base), NAVY_TOP, NAVY_BOTTOM).convert("RGBA")
    circle_mask = Image.new("L", (base, base), 0)
    ImageDraw.Draw(circle_mask).ellipse([4, 4, base - 4, base - 4], fill=255)
    img.paste(grad, (0, 0), circle_mask)

    # mark segitiga "A" sederhana (tiga warna: navy gelap kiri, teal kanan, overlay tengah) mengikuti logo
    cx, cy = base / 2, base / 2 + 6
    h = 108
    w = 124
    top = (cx, cy - h / 2)
    left = (cx - w / 2, cy + h / 2)
    right = (cx + w / 2, cy + h / 2)
    mid = (cx, cy + h / 2 * 0.1)

    draw.polygon([top, left, (cx, cy + h / 2 * 0.35)], fill=ACCENT + (255,))
    draw.polygon([top, right, (cx, cy + h / 2 * 0.35)], fill=TEAL + (255,))
    # batang tengah (crossbar) seperti huruf A
    bar_w = w * 0.52
    bar_h = h * 0.14
    draw.rectangle([cx - bar_w / 2, cy + h * 0.06, cx + bar_w / 2, cy + h * 0.06 + bar_h], fill=(255, 255, 255, 235))

    # ring tipis putih transparan di tepi lingkaran (kesan premium)
    ring = Image.new("RGBA", (base, base), (0, 0, 0, 0))
    ImageDraw.Draw(ring).ellipse([4, 4, base - 4, base - 4], outline=(255, 255, 255, 60), width=4)
    img = Image.alpha_composite(img, ring)

    # Simpan dari gambar sumber beresolusi penuh (256x256); Pillow mengecilkannya sendiri untuk tiap ukuran
    # yang diminta. Sumber yang sudah dikecilkan dulu membuat ukuran lebih besar tidak bisa dibuat (di-skip).
    img.save(OUT / "icon.ico", format="ICO", sizes=[(s, s) for s in sizes])
    print("icon.ico", sizes)


if __name__ == "__main__":
    build_wizard_image()
    build_wizard_small()
    build_icon()
    print("selesai")
