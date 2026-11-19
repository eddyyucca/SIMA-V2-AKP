{{-- Kotak cari karyawan untuk kolom Nama pada formulir request (lihat public/js/employee-picker.js).
     Parameter: ring (warna fokus), id (opsional), placeholder, value, employeeId (pemulihan setelah validasi gagal),
     strict (bawaan true: wajib memilih dari daftar; false = formulir edit, request lama boleh belum terhubung). --}}
<div class="relative" data-ep-wrap>
    <input type="text" name="employee_name" @if (! empty($id)) id="{{ $id }}" @endif data-employee-picker @if ($strict ?? true) data-ep-required="1" @endif
        required autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"
        placeholder="{{ $placeholder ?? 'Ketik nama karyawan, lalu pilih dari daftar' }}" value="{{ $value ?? '' }}"
        class="w-full bg-[#1f2937] border border-slate-700 rounded-lg px-4 py-3 text-sm text-white focus:ring-2 focus:ring-{{ $ring ?? 'purple' }}-500">
    <input type="hidden" name="employee_id" value="{{ $employeeId ?? '' }}" data-ep-id>
    <div data-ep-list class="hidden" role="listbox" style="position:absolute;left:0;right:0;top:100%;margin-top:4px;z-index:60;max-height:260px;overflow-y:auto;background:#fff;border:1px solid #d6deea;border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.18)"></div>
    <div data-ep-hint class="hidden mt-1 text-[11px]"></div>
</div>
