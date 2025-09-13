<?php

namespace App\Http\Controllers;

use App\Models\ChecklistMaster;
use App\Models\ChecklistItem;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ChecklistMasterController extends Controller
{
    /**
     * Menampilkan semua data master checklist beserta item-itemnya.
     */
    public function index()
    {
        // Mengambil semua master checklist beserta relasi 'items'-nya
        return ChecklistMaster::with('items')->latest()->get();
    }

    /**
     * Menyimpan master checklist baru beserta item-itemnya.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'id_karyawan' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.activity_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        // --- LOGIKA SINKRONISASI USER BARU YANG LEBIH EKSPLISIT ---
        $apiService = new API_Service();
        $karyawanData = $apiService->getDataKaryawan(['id_karyawan' => $request->id_karyawan]);

        if (!$karyawanData || !isset($karyawanData[0])) {
            return response()->json(['status' => 'error', 'message' => 'Data karyawan tidak ditemukan.'], 404);
        }
        $detailKaryawan = $karyawanData[0];

        // Langkah 1: Cari user berdasarkan karyawan_id
        $user = User::where('karyawan_id', $request->id_karyawan)->first();

        // Langkah 2: Jika user TIDAK DITEMUKAN, maka buat baru
        if (!$user) {
            $user = User::create([
                'karyawan_id' => $request->id_karyawan,
                'name' => $detailKaryawan['name'],
                'email' => $detailKaryawan['email'] ?? $request->id_karyawan . '@internal.com',
                'password' => bcrypt(Str::random(10))
            ]);
        }
        // --- AKHIR LOGIKA SINKRONISASI ---

        return DB::transaction(function () use ($request, $user) {
            $master = ChecklistMaster::create([
                'checklist_id' => 'CK-' . strtoupper(Str::random(8)),
                'name' => $request->name,
                'type' => $request->type,
                'created_by' => $user->id,
            ]);

            foreach ($request->items as $index => $itemData) {
                ChecklistItem::create([
                    'checklist_master_id' => $master->id,
                    'activity_name' => $itemData['activity_name'],
                    'order' => $index + 1,
                    'is_required' => $itemData['is_required'] ?? true,
                ]);
            }
            return $master->load('items');
        });
    }

    /**
     * Menampilkan satu data master checklist spesifik.
     */
    public function show(ChecklistMaster $checklistMaster)
    {
        return $checklistMaster->load('items');
    }

    /**
     * Mengupdate data master checklist.
     * (Logika update bisa ditambahkan di sini nanti)
     */
    public function update(Request $request, ChecklistMaster $checklistMaster)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string|max:255',
            'items' => 'sometimes|required|array|min:1',
            // Validasi untuk setiap item di dalam array
            'items.*.id' => 'nullable|integer|exists:checklist_items,id',
            'items.*.activity_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        // Memulai transaksi database untuk menjaga integritas data
        return DB::transaction(function () use ($request, $checklistMaster) {
            // 1. Update data master-nya (name, type) jika ada
            $checklistMaster->update($request->only('name', 'type'));

            if ($request->has('items')) {
                $incomingItems = $request->input('items', []);
                $incomingItemIds = array_filter(array_column($incomingItems, 'id'));

                // 2. Hapus item yang tidak ada lagi di request
                ChecklistItem::where('checklist_master_id', $checklistMaster->id)
                    ->whereNotIn('id', $incomingItemIds)
                    ->delete();

                // 3. Update atau Buat item baru
                foreach ($incomingItems as $index => $itemData) {
                    ChecklistItem::updateOrCreate(
                        [
                            // Kunci untuk mencari item
                            'id' => $itemData['id'] ?? null,
                            'checklist_master_id' => $checklistMaster->id
                        ],
                        [
                            // Data untuk di-update atau dibuat
                            'activity_name' => $itemData['activity_name'],
                            'order' => $index + 1,
                            'is_required' => $itemData['is_required'] ?? true
                        ]
                    );
                }
            }

            // Mengembalikan data yang sudah fresh dari database
            return $checklistMaster->load('items');
        });
    }

    /**
     * Menghapus master checklist (soft delete).
     */
    public function destroy(ChecklistMaster $checklistMaster)
    {
        $checklistMaster->delete();
        return response()->json(null, 204);
    }
}