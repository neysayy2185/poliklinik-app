<?php

namespace App\Http\Controllers\Dokter;

use App\Http\Controllers\Controller;
use App\Models\DaftarPoli;
use App\Models\DetailPeriksa; // Pastikan model DetailPeriksa di-import
use App\Models\Obat;
use App\Models\Periksa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; // Import Facade DB untuk transaksi data

class PeriksaPasienController extends Controller
{
    public function index()
    {
        $dokterId = Auth::id();

        $daftarPasien = DaftarPoli::with(['pasien', 'jadwalPeriksa', 'periksas'])
            ->whereHas('jadwalPeriksa', function ($query) use ($dokterId){
                $query->where('id_dokter', $dokterId);
            })
            ->orderBy('no_antrian')
            ->get();

        return view('dokter.periksa-pasien.index', compact('daftarPasien'));
    }

    public function create($id)
    {
        $obats = Obat::all();
        return view('dokter.periksa-pasien.create', compact('obats', 'id'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'obat_json' => 'required',
            'catatan' => 'nullable|string',
            'biaya_periksa' => 'required|integer',
        ]);

        $obatIds = json_decode($request->obat_json, true);

        // Memulai transaksi database agar aman
        DB::beginTransaction();

        try {
            // 1. VALIDASI STOK TERLEBIH DAHULU SEBELUM DISIMPAN
            foreach ($obatIds as $idObat) {
                $obat = Obat::find($idObat);

                if (!$obat || $obat->stok <= 0) {
                    // Batalkan semua proses jika ada obat yang stoknya habis / kosong
                    DB::rollBack();

                    return redirect()->back()
                        ->withInput()
                        ->with('error', "Gagal menyimpan! Obat '" . ($obat->nama_obat ?? 'Tidak Diketahui') . "' stoknya sudah habis.");
                }
            }

            // 2. JIKA SEMUA STOK AMAN, LANJUTKAN PROSES SIMPAN DATA PERIKSA
            $periksa = Periksa::create([
                'id_daftar_poli' => $request->id_daftar_poli,
                'tgl_periksa' => now(),
                'catatan' => $request->catatan,
                'biaya_periksa' => $request->biaya_periksa + 150000,
            ]);

            // 3. SIMPAN DETAIL PERIKSA & AUTOMATIC DECREMENT STOK OBAT
            foreach ($obatIds as $idObat) {
                DetailPeriksa::create([
                    'id_periksa' => $periksa->id,
                    'id_obat' => $idObat,
                ]);

                // Sistem otomatis mengurangi stok obat sebanyak 1 setiap kali diresepkan
                $obat = Obat::find($idObat);
                $obat->decrement('stok', 1);
            }

            // Commit perubahan data jika sukses tanpa error
            DB::commit();

            return redirect()->route('periksa-pasien.index')->with('success', 'Data periksa berhasil disimpan dan stok obat otomatis berkurang.');

        } catch (\Exception $e) {
            // Jika ada error tidak terduga pada sistem, rollback database
            DB::rollBack();
            return redirect()->back()->with('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        }
    }
}
