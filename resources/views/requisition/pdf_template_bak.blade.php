<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Berita Acara Kerusakan/Kehilangan - AKP-BAK</title>
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

        /* Kronologis / Analisa / Rekomendasi */
        .note-box {
            border: 0.5px solid #000000;
            padding: 8px 10px;
            min-height: 70px;
            margin-bottom: 10px;
        }
        .note-title {
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .note-title em {
            font-weight: normal;
            font-style: italic;
        }

        /* Signatures Section */
        .sig-container {
            width: 100%;
            border-collapse: collapse;
        }
        .sig-header {
            font-weight: bold;
            font-size: 10px;
            padding: 4px 0;
            text-transform: uppercase;
        }
        .sig-table {
            width: 100%;
            border-collapse: collapse;
        }
        .sig-table td, .sig-table th {
            border: 0.5px solid #000000;
            text-align: center;
            font-size: 8px;
            padding: 4px 2px;
        }
        .sig-space td {
            padding: 30px 4px;
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

    <div class="doc-title-center">Berita Acara Kerusakan/Kehilangan</div>

    <!-- Info Pemohon -->
    <table class="info-table">
        <tr>
            <td style="width: 22%;">NAMA</td>
            <td style="width: 2%;">:</td>
            <td><strong>{{ $itRequest->requester_name }}</strong></td>
        </tr>
        <tr>
            <td>NIK KARYAWAN</td>
            <td>:</td>
            <td><strong>{{ $itRequest->nik ?? '-' }}</strong></td>
        </tr>
        <tr>
            <td>DEPARTEMENT/JABATAN</td>
            <td>:</td>
            <td><strong>{{ $itRequest->department ?? '-' }}{{ $itRequest->position ? '/' . $itRequest->position : '' }}</strong></td>
        </tr>
        <tr>
            <td>NO. BAK</td>
            <td>:</td>
            <td><strong>{{ $itRequest->ticket_number ?: 'Menunggu Approval' }}</strong></td>
        </tr>
    </table>

    <!-- Main Dynamic Table -->
    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 5%;">NO</th>
                <th style="width: 28%;">ASSET</th>
                <th style="width: 20%;">SPESIFIKASI</th>
                <th style="width: 20%;">SERIAL NUMBER</th>
                <th style="width: 27%;">KETERANGAN</th>
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
                        <td style="text-align: center;">{{ $barang['serial_number'] ?? '-' }}</td>
                        <td>{!! nl2br(e($barang['keterangan'] ?? '-')) !!}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td style="text-align: center;">1</td>
                    <td><strong>{{ $itRequest->title }}</strong></td>
                    <td style="text-align: center;">-</td>
                    <td style="text-align: center;">-</td>
                    <td>{{ $itRequest->admin_notes ?? '-' }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <!-- Kronologis (diisi user) -->
    <div class="note-box">
        <div class="note-title">Kronologis <em>(bagian ini di isi user)</em></div>
        <div>{!! nl2br(e($itRequest->description)) !!}</div>
    </div>

    <!-- Analisa (diisi IT) -->
    <div class="note-box">
        <div class="note-title">Analisa <em>(bagian ini di isi IT)</em></div>
        @if ($itRequest->analisa)
            <div>{!! nl2br(e($itRequest->analisa)) !!}</div>
        @endif
    </div>

    <!-- Rekomendasi (diisi IT) -->
    <div class="note-box">
        <div class="note-title">Rekomendasi <em>(bagian ini di isi IT)</em></div>
        @if ($itRequest->rekomendasi)
            <div>{!! nl2br(e($itRequest->rekomendasi)) !!}</div>
        @endif
    </div>

    <!-- Tanda Tangan Dual Block (USER & IT TEAM) -->
    <table class="sig-container">
        <tr>
            <!-- Block USER -->
            <td style="width: 49%; vertical-align: top;">
                <div class="sig-header">USER</div>
                <table class="sig-table">
                    <tr>
                        <th style="width: 20%;">Request by</th>
                        <th colspan="4" style="width: 80%;">Divalidasi Oleh</th>
                    </tr>
                    <tr class="sig-space">
                        <td style="width: 20%;"></td>
                        <td style="width: 20%;"></td>
                        <td style="width: 20%;"></td>
                        <td style="width: 20%;"></td>
                        <td style="width: 20%;"></td>
                    </tr>
                    <tr style="font-weight: bold;">
                        <td style="width: 20%;">User</td>
                        <td style="width: 20%;">Spv/Spt</td>
                        <td style="width: 20%;">Manager</td>
                        <td style="width: 20%;">Manager</td>
                        <td style="width: 20%;">GM</td>
                    </tr>
                </table>
            </td>

            <td style="width: 2%;"></td>

            <!-- Block IT TEAM -->
            <td style="width: 49%; vertical-align: top;">
                <div class="sig-header">IT TEAM</div>
                <table class="sig-table">
                    <tr>
                        <th style="width: 20%;">Received by</th>
                        <th colspan="4" style="width: 80%;">Divalidasi Oleh</th>
                    </tr>
                    <tr class="sig-space">
                        <td style="width: 20%;"></td>
                        <td style="width: 20%;"></td>
                        <td style="width: 20%;"></td>
                        <td style="width: 20%;"></td>
                        <td style="width: 20%;"></td>
                    </tr>
                    <tr style="font-weight: bold;">
                        <td style="width: 20%;">IT</td>
                        <td style="width: 20%;">Spv/Spt</td>
                        <td style="width: 20%;">Manager</td>
                        <td style="width: 20%;">Manager</td>
                        <td style="width: 20%;">GM</td>
                    </tr>
                </table>
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
