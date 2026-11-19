<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Berita Acara Peminjaman Aset - AKP-PJ</title>
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

        /* Keperluan Peminjaman */
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

        /* Signatures Section (sama pola dengan BAK: Block USER & Block IT TEAM) */
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

    <div class="doc-title-center">Berita Acara Peminjaman Aset</div>

    <!-- Info Peminjam -->
    <table class="info-table">
        <tr>
            <td style="width: 22%;">NAMA PEMINJAM</td>
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
            <td>TANGGAL PINJAM</td>
            <td>:</td>
            <td><strong>{{ optional($itRequest->loan_start_date)->locale('id')->translatedFormat('d F Y') ?? '-' }}</strong></td>
        </tr>
        <tr>
            <td>TANGGAL KEMBALI</td>
            <td>:</td>
            <td>
                <strong>{{ optional($itRequest->loan_end_date)->locale('id')->translatedFormat('d F Y') ?? '-' }}</strong>
                @if ($itRequest->loan_duration_label)
                    <strong>({{ $itRequest->loan_duration_label }})</strong>
                @endif
            </td>
        </tr>
        <tr>
            <td>NO. PEMINJAMAN</td>
            <td>:</td>
            <td><strong>{{ $itRequest->ticket_number ?: 'Menunggu Approval' }}</strong></td>
        </tr>
    </table>

    <!-- Main Dynamic Table -->
    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 5%;">NO</th>
                <th style="width: 28%;">NAMA ASET</th>
                <th style="width: 28%;">SPESIFIKASI</th>
                <th style="width: 20%;">SERIAL NUMBER</th>
                <th style="width: 19%;">KETERANGAN</th>
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
                    <td>-</td>
                </tr>
            @endif
        </tbody>
    </table>

    <!-- Keperluan Peminjaman -->
    <div class="note-box">
        <div class="note-title">Keperluan / Catatan Peminjaman</div>
        <div>{!! nl2br(e($itRequest->description)) !!}</div>
    </div>

    <!-- Tanda Tangan Dual Block (USER & IT TEAM) -->
    <table class="sig-container">
        <tr>
            <!-- Block USER -->
            <td style="width: 49%; vertical-align: top;">
                <div class="sig-header">USER</div>
                <table class="sig-table">
                    <tr>
                        <th style="width: 20%;">Peminjam</th>
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
                        <th style="width: 20%;">Diserahkan Oleh</th>
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

</body>
</html>
