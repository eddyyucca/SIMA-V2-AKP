<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Work Order - AKP-WO</title>
    <style>
        @page {
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
            margin-bottom: 15px;
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
        .doc-title {
            font-size: 20px;
            font-weight: 900;
            color: #0f172a;
            text-align: right;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Identity Section */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .info-table td {
            padding: 3px 4px;
            vertical-align: top;
        }
        .divider-vertical {
            border-right: 0.5px solid #94a3b8;
        }

        /* Box Deskripsi Pekerjaan */
        .reason-box {
            border: 0.5px solid #000000;
            padding: 8px 10px;
            min-height: 120px;
            margin-bottom: 15px;
        }
        .reason-title {
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
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
        }
        .attachment-title {
            font-size: 14px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 12px;
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
            <td style="width: 50%;">
                <div class="doc-title">Work Order</div>
            </td>
        </tr>
    </table>

    <!-- Info Pemohon & Ticket Metadata -->
    <table class="info-table">
        <tr>
            <td style="width: 17%;">Nama Karyawan</td>
            <td style="width: 2%;">:</td>
            <td style="width: 31%;" class="divider-vertical"><strong>{{ $itRequest->requester_name }}</strong></td>

            <td style="width: 18%; padding-left: 10px;">Status</td>
            <td style="width: 2%;">:</td>
            <td style="width: 30%;"><strong>{{ $itRequest->status ?? '-' }}</strong></td>
        </tr>
        <tr>
            <td>NIK Karyawan</td>
            <td>:</td>
            <td class="divider-vertical"><strong>{{ $itRequest->nik ?? '-' }}</strong></td>

            <td style="padding-left: 10px;">Hari/Tanggal</td>
            <td>:</td>
            <td>{{ $itRequest->created_at->locale('id')->translatedFormat('l, d F Y') }}</td>
        </tr>
        <tr>
            <td>Department/Jabatan</td>
            <td>:</td>
            <td class="divider-vertical"><strong>{{ $itRequest->department ?? '-' }}{{ $itRequest->position ? '/' . $itRequest->position : '' }}</strong></td>

            <td style="padding-left: 10px;">NO. WO</td>
            <td>:</td>
            <td><strong>{{ $itRequest->ticket_number ?: 'Menunggu Approval' }}</strong></td>
        </tr>
    </table>

    <!-- Judul Pekerjaan -->
    <table class="info-table" style="margin-bottom: 8px;">
        <tr>
            <td style="width: 17%;">Judul Pekerjaan</td>
            <td style="width: 2%;">:</td>
            <td><strong>{{ $itRequest->title }}</strong></td>
        </tr>
    </table>

    <!-- Rincian Pekerjaan -->
    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 5%;">NO</th>
                <th style="width: 35%;">PEKERJAAN / BARANG</th>
                <th style="width: 20%;">LOKASI</th>
                <th style="width: 10%;">QTY</th>
                <th style="width: 30%;">KETERANGAN</th>
            </tr>
        </thead>
        <tbody>
            @php
                $itemRows = !empty($itRequest->items) ? $itRequest->items : null;
            @endphp
            @if ($itemRows)
                @foreach ($itemRows as $index => $item)
                    <tr>
                        <td style="text-align: center;">{{ $index + 1 }}</td>
                        <td><strong>{{ $item['name'] ?? '-' }}</strong></td>
                        <td style="text-align: center;">{{ $item['lokasi'] ?? '-' }}</td>
                        <td style="text-align: center;">{{ $item['qty'] ?? 1 }}</td>
                        <td>{!! nl2br(e($item['keterangan'] ?? '-')) !!}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td style="text-align: center;">1</td>
                    <td><strong>{{ $itRequest->title }}</strong></td>
                    <td style="text-align: center;">-</td>
                    <td style="text-align: center;">{{ $itRequest->qty ?? 1 }}</td>
                    <td>-</td>
                </tr>
            @endif
        </tbody>
    </table>

    <!-- Deskripsi Pekerjaan Box -->
    <div class="reason-box">
        <div class="reason-title">Deskripsi Pekerjaan :</div>
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
