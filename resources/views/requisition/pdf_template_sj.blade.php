<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Jalan - AKP-SJ</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 20px 25px 25px 25px;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #0f172a;
            line-height: 1.3;
        }

        /* Top Header */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .logo-img {
            height: 34px;
        }
        .company-name {
            font-size: 12px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2px;
        }
        .hari-tgl {
            font-size: 11px;
            text-align: right;
        }
        .doc-title-center {
            font-size: 18px;
            font-weight: 900;
            color: #0f172a;
            text-align: center;
            text-transform: uppercase;
            text-decoration: underline;
            letter-spacing: 0.5px;
            margin: 15px 0 18px 0;
        }

        /* Identity Section */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .info-table td {
            padding: 4px 4px;
            vertical-align: bottom;
        }

        /* Main Item Table */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .main-table th {
            background-color: #d9d9d9;
            color: #0f172a;
            font-size: 9.5px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 7px 4px;
            border: 0.5px solid #000000;
            text-align: center;
        }
        .main-table td {
            border: 0.5px solid #000000;
            padding: 10px 6px;
            vertical-align: top;
        }

        /* Catatan Pengiriman */
        .note-box {
            border: 0.5px solid #000000;
            padding: 8px 10px;
            min-height: 55px;
            margin-bottom: 15px;
        }
        .note-title {
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
        }

        /* Signatures Section - 2 kolom sederhana */
        .sig-container {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .sig-box {
            border: 0.5px solid #000000;
            padding: 8px 10px;
            vertical-align: top;
        }
        .sig-box-title {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 40px;
        }
        .sig-line {
            border-top: 0.5px solid #000000;
            padding-top: 4px;
            font-size: 9px;
        }

        /* Lampiran (halaman berikutnya) */
        .attachment-page {
            page-break-before: always;
            text-align: center;
        }
        .attachment-title {
            font-size: 14px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 12px;
            text-align: left;
        }
        .attachment-image {
            max-width: 80%;
            max-height: 500px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <table class="header-table">
        <tr>
            <td style="width: 50%;">
                @if (file_exists(public_path('images/logo-akp.png')))
                    <img src="{{ public_path('images/logo-akp.png') }}" class="logo-img" alt="Logo">
                @endif
                <div class="company-name">PT ADHI KARTIKO PRATAMA TBK</div>
            </td>
            <td style="width: 50%;" class="hari-tgl">
                {{ $itRequest->created_at->locale('id')->translatedFormat('l, d F Y') }}
            </td>
        </tr>
    </table>

    <div class="doc-title-center">Surat Jalan</div>

    <!-- Info Pengirim & Tujuan -->
    <table class="info-table">
        <tr>
            <td style="width: 22%;">NAMA PENGIRIM</td>
            <td style="width: 2%;">:</td>
            <td><strong>{{ $itRequest->requester_name }}</strong></td>
        </tr>
        <tr>
            <td>DEPARTEMENT/JABATAN</td>
            <td>:</td>
            <td><strong>{{ $itRequest->department ?? '-' }}{{ $itRequest->position ? '/' . $itRequest->position : '' }}</strong></td>
        </tr>
        <tr>
            <td style="vertical-align: top;">TUJUAN PENGIRIMAN</td>
            <td style="vertical-align: top;">:</td>
            <td><strong>{!! nl2br(e($itRequest->title)) !!}</strong></td>
        </tr>
        <tr>
            <td style="vertical-align: top;">PENERIMA</td>
            <td style="vertical-align: top;">:</td>
            <td>
                <strong>{{ $itRequest->recipient_name ?? '-' }}</strong>
                @if ($itRequest->recipient_phone)
                    <br><strong>{{ $itRequest->recipient_phone }}</strong>
                @endif
            </td>
        </tr>
        <tr>
            <td>NO. SURAT JALAN</td>
            <td>:</td>
            <td><strong>{{ $itRequest->ticket_number ?: 'Menunggu Approval' }}</strong></td>
        </tr>
    </table>

    <!-- Main Dynamic Table -->
    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 5%;">NO</th>
                <th style="width: 30%;">NAMA BARANG</th>
                <th style="width: 20%;">SPESIFIKASI</th>
                <th style="width: 12%;">QTY</th>
                <th style="width: 33%;">SERIAL NUMBER</th>
            </tr>
        </thead>
        <tbody>
            @php
                $itemRows = !empty($itRequest->items) ? $itRequest->items : null;
            @endphp
            @if ($itemRows)
                @foreach ($itemRows as $index => $barang)
                    <tr>
                        <td style="text-align: center;">{{ $index + 1 }}</td>
                        <td><strong>{{ $barang['name'] ?? '-' }}</strong></td>
                        <td style="text-align: center;">{{ $barang['spesifikasi'] ?? '-' }}</td>
                        <td style="text-align: center;">{{ $barang['qty'] ?? 1 }}</td>
                        <td style="text-align: center;">{{ $barang['serial_number'] ?? '-' }}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td style="text-align: center;">1</td>
                    <td><strong>{{ $itRequest->title }}</strong></td>
                    <td style="text-align: center;">-</td>
                    <td style="text-align: center;">{{ $itRequest->qty ?? 1 }}</td>
                    <td style="text-align: center;">-</td>
                </tr>
            @endif
        </tbody>
    </table>

    <!-- Catatan Pengiriman -->
    <div class="note-box">
        <div class="note-title">Catatan Pengiriman</div>
        <div>{!! nl2br(e($itRequest->description)) !!}</div>
    </div>

    <!-- Tanda Tangan 3 Kolom -->
    <table class="sig-container">
        <tr>
            <td style="width: 32%;" class="sig-box">
                <div class="sig-box-title">Pengirim</div>
                <div class="sig-line">Nama & Tanda Tangan &nbsp;&nbsp;&nbsp; Tanggal: ______________</div>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 32%;" class="sig-box">
                <div class="sig-box-title">Mengetahui</div>
                <div class="sig-line">Nama & Tanda Tangan &nbsp;&nbsp;&nbsp; Tanggal: ______________</div>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 32%;" class="sig-box">
                <div class="sig-box-title">Penerima</div>
                <div class="sig-line">Nama & Tanda Tangan &nbsp;&nbsp;&nbsp; Tanggal: ______________</div>
            </td>
        </tr>
    </table>

    @if ($itRequest->attachment && file_exists(storage_path('app/public/' . $itRequest->attachment)))
        <div class="attachment-page">
            <div class="attachment-title">Lampiran</div>
            <img src="{{ storage_path('app/public/' . $itRequest->attachment) }}" class="attachment-image">
        </div>
    @endif

</body>
</html>
