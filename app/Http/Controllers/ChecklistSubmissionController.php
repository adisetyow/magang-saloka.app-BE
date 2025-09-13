<?php

namespace App\Http\Controllers;

use App\Models\ChecklistSubmission;
use App\Models\ChecklistSubmissionDetail;
use App\Models\ChecklistSchedule;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class ChecklistSubmissionController extends Controller
{
    /**
     * Menampilkan daftar checklist submission berdasarkan tanggal.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        // Query dasar dengan relasi yang dibutuhkan
        $query = ChecklistSubmission::with([
            'schedule.master:id,name,type', // Ambil info dari master
            'user:id,name' // Ambil nama user yang mengerjakan
        ]);

        // Jika ada parameter tanggal di request, filter berdasarkan tanggal tersebut.
        if ($request->has('date') && $request->input('date') != '') {
            $targetDate = Carbon::parse($request->input('date'));
            $query->whereDate('submission_date', $targetDate->toDateString());
        }

        // Ambil data terbaru di paling atas
        $submissions = $query->latest('submission_date')->get();

        return $submissions;
    }

    /**
     * Menampilkan detail item dari satu submission.
     */
    public function show($submissionId)
    {
        $submission = ChecklistSubmission::with([
            'schedule.master:id,name',
            'details.item:id,activity_name,is_required' // Ambil detail item
        ])->findOrFail($submissionId);

        return $submission;
    }

    /**
     * Menyimpan aksi ceklis dari user.
     */
    public function storeCheck(Request $request, $submissionDetailId)
    {
        $validator = Validator::make($request->all(), [
            'is_checked' => 'required|boolean',
            'notes' => 'nullable|string|max:500',
            'id_karyawan' => 'required|string|exists:users,karyawan_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->errors()], 422);
        }

        $detail = ChecklistSubmissionDetail::findOrFail($submissionDetailId);
        $submission = $detail->submission;

        // Logika untuk sinkronisasi user yang mengerjakan
        // (Ini bisa dibuat helper function terpisah nanti)
        $user = \App\Models\User::firstOrCreate(['karyawan_id' => $request->id_karyawan]);

        // Update detail item yang dicek
        $detail->update([
            'is_checked' => $request->is_checked,
            'notes' => $request->notes
        ]);

        // Update 'kepala' submission untuk menandakan siapa yang terakhir mengerjakan
        $submission->update([
            'submitted_by' => $user->id
        ]);

        // Cek apakah semua item sudah selesai untuk mengupdate status utama
        $this->updateSubmissionStatus($submission);

        return $detail;
    }

    public function startOrGetTodaySubmission(ChecklistSchedule $schedule)
    {
        // ====================================================================
        // --- KODE BARU YANG LEBIH AMAN ---
        // ====================================================================

        // Langkah 1: Muat relasi master secara eksplisit.
        $schedule->load('master');

        // Langkah 2: Lakukan pengecekan yang ketat.
        // Jika master tidak ada (karena soft-delete) atau tidak ditemukan, lemparkan error.
        if (!$schedule->master) {
            throw new Exception("Gagal memulai checklist: Master Checklist untuk jadwal ini tidak ditemukan atau telah dihapus.");
        }

        // Muat relasi items setelah kita yakin master-nya ada.
        $schedule->master->load('items');

        // Jika master ada tapi tidak punya item, lemparkan error.
        if ($schedule->master->items->isEmpty()) {
            throw new Exception("Gagal memulai checklist: Master Checklist ini tidak memiliki satupun activity item.");
        }

        $today = Carbon::now()->toDateString();

        $submission = ChecklistSubmission::firstOrCreate(
            [
                'checklist_schedule_id' => $schedule->id,
                'submission_date' => $today,
            ],
            [
                'submitted_by' => $schedule->created_by,
                'status' => 'pending',
            ]
        );

        if ($submission->wasRecentlyCreated) {
            // Karena kita sudah memuat relasi di atas, loop ini sekarang dijamin aman.
            foreach ($schedule->master->items as $item) {
                ChecklistSubmissionDetail::create([
                    'submission_id' => $submission->id,
                    'item_id' => $item->id,
                    'is_checked' => false,
                ]);
            }
        }

        // Kembalikan submission yang sudah siap.
        return $submission;
    }
    /**
     * Update status submission (pending, incomplete, completed).
     */
    private function updateSubmissionStatus(ChecklistSubmission $submission)
    {
        $totalItems = $submission->details()->count();
        $checkedItems = $submission->details()->where('is_checked', true)->count();

        $newStatus = 'pending';
        if ($checkedItems > 0 && $checkedItems < $totalItems) {
            $newStatus = 'incomplete';
        } elseif ($checkedItems === $totalItems) {
            $newStatus = 'completed';
        }

        $submission->update(['status' => $newStatus]);
    }
}