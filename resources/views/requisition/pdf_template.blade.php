<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>IT Requisition - AKP-ITR</title>
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

        /* Main Item Table */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .main-table th {
            background-color: #ffffff;
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

        /* Box Alasan Permintaan */
        .reason-box {
            border: 0.5px solid #000000;
            padding: 8px 10px;
            min-height: 55px;
            margin-bottom: 8px;
        }
        .reason-title {
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
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
    
    /* Tambahkan ini */
    display: block; /* Ubah dari inline menjadi block */
    margin-left: auto; /* Margin kiri otomatis */
    margin-right: auto; /* Margin kanan otomatis */
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
                <div class="doc-title">IT REQUISITION</div>
            </td>
        </tr>
    </table>

    <!-- Info Pemohon & Ticket Metadata -->
    <table class="info-table">
        <tr>
            <td style="width: 17%;">Nama Karyawan</td>
            <td style="width: 2%;">:</td>
            <td style="width: 31%;" class="divider-vertical"><strong>{{ $itRequest->requester_name }}</strong></td>
            
            <td style="width: 18%; padding-left: 10px;">Status Permintaan</td>
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
            <td>Departement/Jabatan</td>
            <td>:</td>
            <td class="divider-vertical"><strong>{{ $itRequest->department ?? '-' }}{{ $itRequest->position ? '/' . $itRequest->position : '' }}</strong></td>
            
            <td style="padding-left: 10px;">NO. ITR</td>
            <td>:</td>
            <td><strong>{{ $itRequest->ticket_number ?? 'Menunggu Approval' }}</strong></td>
        </tr>
    </table>

    <!-- Main Dynamic Table -->
    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 5%;">NO</th>
                <th style="width: 32%;">ITEM</th>
                <th style="width: 20%;">SPESIFIKASI</th>
                <th style="width: 10%;">QTY</th>
                <th style="width: 33%;">KETERANGAN</th>
            </tr>
        </thead>
        <tbody>
            @php
                $deviceRows = !empty($itRequest->items) ? $itRequest->items : null;
            @endphp
            @if ($deviceRows)
                @foreach ($deviceRows as $index => $device)
                    <tr>
                        <td style="text-align: center;">{{ $index + 1 }}</td>
                        <td><strong>{{ $device['name'] ?? '-' }}</strong></td>
                        <td style="text-align: center;">{{ $device['spesifikasi'] ?? '-' }}</td>
                        <td style="text-align: center;">{{ $device['qty'] ?? 1 }}</td>
                        <td>{!! nl2br(e($device['keterangan'] ?? '-')) !!}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td style="text-align: center;">1</td>
                    <td>
                        <strong>{{ $itRequest->title }}</strong>
                        <div style="margin-top: 4px; color: #334155; min-height: 40px;">{!! nl2br(e($itRequest->description)) !!}</div>
                    </td>
                    <td style="text-align: center;">-</td>
                    <td style="text-align: center;">{{ $itRequest->qty ?? 1 }}</td>
                    <td>{{ $itRequest->admin_notes ?? '-' }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <!-- Alasan Permintaan Box -->
    <div class="reason-box">
        <div class="reason-title">Alasan Permintaan :</div>
        <div>{{ $itRequest->description }}</div>
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
