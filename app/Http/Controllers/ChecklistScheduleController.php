<?php

namespace App\Http\Controllers;

use App\Models\ChecklistMaster;
use App\Models\ChecklistSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChecklistScheduleController extends Controller
{
    public function index()
    {
        // Ambil jadwal 
        return ChecklistSchedule::with('master:id,name')->latest()->get();
    }
    /**
     * Menyimpan jadwal baru untuk sebuah Master Checklist.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'checklist_master_id' => 'required|exists:checklist_masters,id',
            'schedule_name' => 'required|string|max:255',
            'periode_type' => 'required|in:harian,mingguan,bulanan,tertentu',
            'schedule_details' => 'nullable|array', // Menerima array untuk hari/tanggal
            'end_date' => 'nullable|date_format:Y-m-d',
            'id_karyawan' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        // Kita panggil lagi logika sinkronisasi user
        $user = $this->synchronizeUser($request->id_karyawan);
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Data karyawan tidak ditemukan.'], 404);
        }


        $schedule = ChecklistSchedule::create([
            'checklist_master_id' => $request->checklist_master_id,
            'schedule_name' => $request->schedule_name,
            'periode_type' => $request->periode_type,
            'schedule_details' => $request->schedule_details, // Laravel akan otomatis encode ke JSON
            'created_by' => $user->id,
            'end_date' => $request->end_date,
        ]);

        return $schedule;
    }

    public function update(Request $request, ChecklistSchedule $schedule)
    {
        $validator = Validator::make($request->all(), [
            'checklist_master_id' => 'required|exists:checklist_masters,id',
            'schedule_name' => 'required|string|max:255',
            'periode_type' => 'required|in:harian,mingguan,bulanan,tertentu',
            'schedule_details' => 'nullable|array',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        $schedule->update([
            'checklist_master_id' => $request->checklist_master_id,
            'schedule_name' => $request->schedule_name,
            'periode_type' => $request->periode_type,
            'schedule_details' => $request->schedule_details,
            'end_date' => $request->end_date,
        ]);

        return $schedule;
    }

    /**
     * Menghapus data jadwal.
     */
    public function destroy(ChecklistSchedule $schedule)
    {
        $schedule->delete();
        return response()->json(null, 204); // Standar respons untuk delete sukses
    }

    public function show(ChecklistSchedule $schedule)
    {
        // Langsung kembalikan data jadwal yang sudah ditemukan oleh Laravel
        return $schedule;
    }


    /**
     * Helper function untuk sinkronisasi user (menghindari duplikasi kode).
     * Anda bisa memindahkan ini ke Class tersendiri nanti.
     */
    private function synchronizeUser($karyawanId)
    {
        $apiService = new API_Service();
        $karyawanData = $apiService->getDataKaryawan(['id_karyawan' => $karyawanId]);

        if (!$karyawanData || !isset($karyawanData[0])) {
            return null;
        }
        $detailKaryawan = $karyawanData[0];

        return \App\Models\User::firstOrCreate(
            ['karyawan_id' => $karyawanId],
            [
                'name' => $detailKaryawan['name'],
                'email' => $detailKaryawan['email'] ?? $karyawanId . '@internal.com',
                'password' => bcrypt(\Illuminate\Support\Str::random(10))
            ]
        );
    }
}