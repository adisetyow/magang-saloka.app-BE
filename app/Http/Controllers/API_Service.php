<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class API_Service extends Controller
{
    public function getDataKaryawan($request)
    {
        $idKaryawan = $request['id_karyawan'];

        $fakeUserData = [
            [
                "id_karyawan" => $idKaryawan,
                // "kaaryawan_id" => $karyawan_id ?? null,
                "name" => "User Lokal (ID: " . $idKaryawan . ")",
                "email" => "user." . $idKaryawan . "@testing.com",

                // Tambahkan field lain jika dibutuhkan oleh kode Anda
            ]
        ];

        return $fakeUserData;


        //KODE ASLI//

        // try
        // {
        //     $result=[];
        //     $urlServerLokaHR = config('app.apiLokaHR');
        //     $client = new \GuzzleHttp\Client();
        //     $response = $client->request('GET', $urlServerLokaHR.'get_karyawan_byID', [
        //         'json' => [
        //             'id_karyawan' => $idKaryawan,
        //         ],
        //     ]);  
        //     $jsonData = json_decode($response->getBody(), true);

        //     if($jsonData['status']=='success' && $jsonData['data']!=null)
        //     {
        //         $result = $jsonData['data'];
        //     }
        //     else
        //     {
        //        $result='failed response API LOKAHR';
        //     }

        //     return $result;
        // } catch (\Exception $ex) {
        //     dd($ex);
        //     return $ex;
        // }
    }
}
