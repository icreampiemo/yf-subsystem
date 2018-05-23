<?php
namespace App\Http\Controllers\QCDB;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CommonController;
use DB;
use Config;
use Yajra\Datatables\Datatables;
use Illuminate\Support\Facades\Auth; #Auth facade
use Dompdf\Dompdf;
use Carbon\Carbon;
use App\IQCInspection;
use Illuminate\Http\Request;
use App\Http\Requests;
use Excel;
use Event;
use App\Events\UpdateIQCInspection;

class IQCInspectionController extends Controller
{
    protected $mysql;
    protected $mssql;
    protected $common;
    protected $wbs;

    public function __construct()
    {
        $this->middleware('auth');
        $com = new CommonController;

        if (Auth::user() != null) {
            $this->mysql = $com->userDBcon(Auth::user()->productline,'mysql');
            $this->wbs = $com->userDBcon(Auth::user()->productline,'wbs');
            $this->mssql = $com->userDBcon(Auth::user()->productline,'mssql');
            $this->common = $com->userDBcon(Auth::user()->productline,'common');
        } else {
            return redirect('/');
        }
    }

    public function getIQCInspection(Request $request)
    {
        $common = new CommonController;
        if(!$common->getAccessRights(Config::get('constants.MODULE_CODE_IQCINS'), $userProgramAccess))
        {
            return redirect('/home');
        }
        else
        {

            return view('qcdb.iqcinspection',[
                        'userProgramAccess' => $userProgramAccess]);
        }
    }

    public function getInvoiceItems(Request $req)
    {
        $db = DB::connection($this->wbs)->table('tbl_wbs_material_receiving_batch')
                ->select('item as id','item as text')
                ->where('not_for_iqc',0)
                ->where('invoice_no',$req->invoiceno)
                ->where('judgement', null)
                ->orWhere('judgement','')
                ->orWhere('judgement','On-going')
                ->distinct()
                ->get();
        if ($this->checkIfExistObject($db) > 0) {
            return $db;
            //$obj = json_decode(json_encode($array));
        } else {
            return null;
        }
    }

    private function formatDate($date, $format)
    {
        if(empty($date))
        {
            return null;
        }
        else
        {
            return date($format,strtotime($date));
        }
    }

    private function insertToInspection($req,$lots)
    {
        foreach ($lots as $key => $lot) {
            $lot_qty = $this->getLotQty($req->invoice_no,$req->partcode,$lot);
            $status = 0;
            $kitting = 0;

            if ($req->judgement == 'Accepted') {
                $status = 1;
                $kitting = 1;
            } 

            if ($req->judgement == 'Rejected') {
                $status = 2;
                $kitting = 0;
            }

            DB::connection($this->mysql)->table('iqc_inspections')
                ->insert([
                    'invoice_no' => $req->invoice_no,
                    'partcode' => $req->partcode,
                    'partname' => $req->partname,
                    'supplier' => $req->supplier,
                    'app_date' => $req->app_date,
                    'app_time' => $req->app_time,
                    'app_no' => $req->app_no,
                    'lot_no' => $lot,
                    'lot_qty' => $lot_qty,
                    'type_of_inspection' => $req->type_of_inspection,
                    'severity_of_inspection' => $req->severity_of_inspection,
                    'inspection_lvl' => $req->inspection_lvl,
                    'aql' => $req->aql,
                    'accept' => $req->accept,
                    'reject' => $req->reject,
                    'date_ispected' => $req->date_inspected,
                    'ww' => $req->ww,
                    'fy' => $req->fy,
                    'shift' => $req->shift,
                    'time_ins_from' => $req->time_ins_from,
                    'time_ins_to' => $req->time_ins_to,
                    'inspector' => $req->inspector,
                    'submission' => $req->submission,
                    'judgement' => $req->judgement,
                    'lot_inspected' => $req->lot_inspected,
                    'lot_accepted' => $req->lot_accepted,
                    'sample_size' => $req->sample_size,
                    'no_of_defects' => $req->no_of_defects,
                    'remarks' => $req->remarks,
                    'classification' => $req->classification,
                    'dbcon' => Auth::user()->productline,
                    'updated_at' => Carbon::now(),
                ]);

                DB::connection($this->wbs)->table('tbl_wbs_material_receiving_batch')
                    ->where('not_for_iqc',0)
                    ->where('invoice_no',$req->invoice_no)
                    ->where('item',$req->partcode)
                    ->where('lot_no',$lot)
                    ->update([
                        'iqc_status' => $status,
                        'for_kitting' => $kitting,
                        'iqc_result' => $req->remarks,
                        'judgement' => $req->judgement,
                        'ins_date' => $this->formatDate($req->date_inspected,'m/d/Y'),
                        'ins_time' => $req->time_ins_to,
                        'ins_by' => $req->inspector,
                        'updated_at' => Carbon::now(),
                    ]);
                DB::connection($this->wbs)->table('tbl_wbs_inventory')
                    ->where('not_for_iqc',0)
                    ->where('invoice_no',$req->invoice_no)
                    ->where('item',$req->partcode)
                    ->where('lot_no',$lot)
                    ->update([
                        'iqc_status' => $status,
                        'for_kitting' => $kitting,
                        'iqc_result' => $req->remarks,
                        'judgement' => $req->judgement,
                        'ins_date' => $this->formatDate($req->date_inspected,'m/d/Y'),
                        'ins_time' => $req->time_ins_to,
                        'ins_by' => $req->inspector,
                        'updated_at' => Carbon::now(),
                    ]);
        }
    }

    public function saveInspection(Request $req)
    {
        $data = [
            'return_status' => 'failed',
            'msg' => "Saving Failed."
        ];
        $query = false;

        if ($req->save_status == 'ADD') {
            if (is_string($req->lot_no)) {
                $lots = explode(',',$req->lot_no);
                $this->insertToInspection($req,$lots);
                $this->insertHistory($lots,$req);
            } else {
                $this->insertToInspection($req,$req->lot_no);
                $this->insertHistory($req->lot_no,$req);
            }

            $query = true;

        } else {
            if (is_string($req->lot_no)) {
                $lots = explode(',',$req->lot_no);
                $this->updateInspection($req,$lots);
                $this->insertHistory($lots,$req);
            } else {
                $this->updateInspection($req,$req->lot_no);
                $this->insertHistory($req->lot_no,$req);
            }
            $query = true;
        }

        if ($query) {
            Event::fire(new UpdateIQCInspection($this->wbs));
            $data = [
                'return_status' => 'success',
                'msg' => "Successfully Saved."
            ];
        }

        return $data;

    }

    private function updateInspection($req,$lots)
    {
        foreach ($lots as $key => $lot) {
            $lot_qty = $this->getLotQty($req->invoice_no,$req->partcode,$lot);
            $status = 0;
            $kitting = 0;

            if ($req->judgement == 'Accepted') {
                $status = 1;
                $kitting = 1;
            } 

            if ($req->judgement == 'Rejected') {
                $status = 2;
                $kitting = 0;
            }
            DB::connection($this->wbs)->table('tbl_wbs_material_receiving_batch')
                ->where('not_for_iqc',0)
                ->where('invoice_no',$req->invoice_no)
                ->where('item',$req->partcode)
                ->where('lot_no',$lot)
                ->update([
                    'iqc_status' => $status,
                    'for_kitting' => $kitting,
                    'iqc_result' => $req->remarks,
                    'judgement' => $req->judgement,
                    'ins_date' => $this->formatDate($req->date_inspected,'m/d/Y'),
                    'ins_time' => $req->time_ins_to,
                    'ins_by' => $req->inspector,
                    'updated_at' => Carbon::now(),
                ]);
            DB::connection($this->wbs)->table('tbl_wbs_inventory')
                ->where('not_for_iqc',0)
                ->where('invoice_no',$req->invoice_no)
                ->where('item',$req->partcode)
                ->where('lot_no',$lot)
                ->update([
                    'iqc_status' => $status,
                    'for_kitting' => $kitting,
                    'iqc_result' => $req->remarks,
                    'judgement' => $req->judgement,
                    'ins_date' => $this->formatDate($req->date_inspected,'m/d/Y'),
                    'ins_time' => $req->time_ins_to,
                    'ins_by' => $req->inspector,
                    'updated_at' => Carbon::now(),
                ]);
        }

        DB::connection($this->mysql)->table('iqc_inspections')
            ->where('id',$req->id)
            ->update([
                'partcode' => $req->partcode,
                'partname' => $req->partname,
                'supplier' => $req->supplier,
                'app_date' => $req->app_date,
                'app_time' => $req->app_time,
                'app_no' => $req->app_no,
                'lot_no' => $req->lot_no,
                'lot_qty' => $req->lot_qty,
                'type_of_inspection' => $req->type_of_inspection,
                'severity_of_inspection' => $req->severity_of_inspection,
                'inspection_lvl' => $req->inspection_lvl,
                'aql' => $req->aql,
                'accept' => $req->accept,
                'reject' => $req->reject,
                'date_ispected' => $req->date_inspected,
                'ww' => $req->ww,
                'fy' => $req->fy,
                'shift' => $req->shift,
                'time_ins_from' => $req->time_ins_from,
                'time_ins_to' => $req->time_ins_to,
                'inspector' => $req->inspector,
                'submission' => $req->submission,
                'judgement' => $req->judgement,
                'lot_inspected' => $req->lot_inspected,
                'lot_accepted' => $req->lot_accepted,
                'sample_size' => $req->sample_size,
                'no_of_defects' => $req->no_of_defects,
                'remarks' => $req->remarks,
                'dbcon' => Auth::user()->productline,
                'updated_at' => Carbon::now(),
            ]);
    }

    private function insertHistory($lots,$req)
    {
        foreach ($lots as $key => $lot) {
            $lot_qty = $this->getLotQty($req->invoice,$req->partcode,$lot);

            DB::connection($this->mysql)->table('iqc_inspections_history')
                ->insert([
                    'invoice_no' => $req->invoice_no,
                    'partcode' => $req->partcode,
                    'partname' => $req->partname,
                    'supplier' => $req->supplier,
                    'app_date' => $req->app_date,
                    'app_time' => $req->app_time,
                    'app_no' => $req->app_no,
                    'lot_no' => $lot,
                    'lot_qty' => $lot_qty,
                    'type_of_inspection' => $req->type_of_inspection,
                    'severity_of_inspection' => $req->severity_of_inspection,
                    'inspection_lvl' => $req->inspection_lvl,
                    'aql' => $req->aql,
                    'accept' => $req->accept,
                    'reject' => $req->reject,
                    'date_ispected' => $req->date_inspected,
                    'ww' => $req->ww,
                    'fy' => $req->fy,
                    'shift' => $req->shift,
                    'time_ins_from' => $req->time_ins_from,
                    'time_ins_to' => $req->time_ins_to,
                    'inspector' => $req->inspector,
                    'submission' => $req->submission,
                    'judgement' => $req->judgement,
                    'lot_inspected' => $req->lot_inspected,
                    'lot_accepted' => $req->lot_accepted,
                    'sample_size' => $req->sample_size,
                    'no_of_defects' => $req->no_of_defects,
                    'remarks' => $req->remarks,
                    'dbcon' => Auth::user()->productline,
                    'created_at' => Carbon::now(),
                ]);
        }
    }

    private function requalifyInventory($app_no,$partcode,$lot)
    {
        DB::connection($this->wbs)->table('tbl_wbs_inventory')
            ->where('wbs_mr_id', $app_no)
            ->where('item', $partcode)
            ->where('lot_no', $lot)
            ->update([
                'received_date' => date('Y-m-d')
            ]);
    }

    public function getShift(Request $req)
    {
        $shift = '';
        $from = Carbon::parse($req->from);
        $to = Carbon::parse($req->to);

        if ($req->from == '7:30 AM' && $req->to == '7:30 PM') {
            $shift = 'Shift A';
        }

        if ($req->from == '7:30 PM' && $req->to == '7:30 AM') {
            $shift = 'Shift B';
        }

        if ($from->hour < $to->hour) {
            $shift = 'Shift A';
        }

        if ($from->hour > $to->hour) {
            $shift = 'Shift B';
        }

        return $shift;
    }

    public function getInvoiceItemDetails(Request $req)
    {
        $db = DB::connection($this->wbs)->table('tbl_wbs_material_receiving_batch as b')
                ->join('tbl_wbs_material_receiving as m','m.receive_no','=','b.wbs_mr_id')
                ->select('b.item_desc',
                        'b.supplier',
                        'm.app_time',
                        'm.app_date',
                        'm.receive_no',
                        DB::raw("SUM(qty) as lot_qty"))
                ->where('m.invoice_no',$req->invoiceno)
                ->where('b.item',$req->item)
                ->first();

        $lot = DB::connection($this->wbs)->table('tbl_wbs_material_receiving_batch')
                ->where('invoice_no',$req->invoiceno)
                ->where('item',$req->item)
                ->where('not_for_iqc',0)
                ->select('lot_no as id','lot_no as text')
                ->get();

        if ($this->checkIfExistObject($db) > 0 && $this->checkIfExistObject($lot) > 0) {
            return $data = [
                'lot' => $lot,
                'details' => $db
            ];
        }
    }

    public function calculateLotQty(Request $req)
    {
        $lot_qty = 0;
        if (empty($req->lot_no)) {
            return $lot_qty;
        } else {
            foreach ($req->lot_no as $key => $lot) {
                $db = DB::connection($this->wbs)->table('tbl_wbs_material_receiving_batch')
                        ->select('qty as lot_qty')
                        ->where('item',$req->item)
                        ->where('invoice_no',$req->invoiceno)
                        ->where('lot_no',$lot)
                        ->first();
                if ($this->checkIfExistObject($db) > 0) {
                    $lot_qty = $lot_qty + $db->lot_qty;
                }
            }
            return $lot_qty;
        }
    }

    private function getLotQty($invoice,$item,$lot)
    {
        $lot_qty = 0;
        $db = DB::connection($this->wbs)->table('tbl_wbs_material_receiving_batch')
                ->select('qty as lot_qty')
                ->where('item',$item)
                ->where('invoice_no',$invoice)
                ->where('lot_no',$lot)
                ->first();
        if ($this->checkIfExistObject($db) > 0) {
            $lot_qty = $db->lot_qty;
        }

        return $lot_qty;
    }

    public function SamplingPlan(Request $req)
    {
        $size = 0;
        $accept = 0;
        $reject = 1;
        if ($req->soi == 'Normal') {
            if ($req->il == 'S2') {
                if ($req->aql == 0.65) {
                    if ($req->lot_qty <= 20) {
                        $size = $req->lot_qty;
                    }

                    if ($req->lot_qty > 20) {
                        $size = 20;
                    }
                }
            }

            if ($req->il == 'S3') {
                if ($req->aql == 0.40) {
                    if ($req->lot_qty <= 32) {
                        $size = $req->lot_qty;
                    }

                    if ($req->lot_qty > 32) {
                        $size = 32;
                    }
                }

                if ($req->aql == 1.00) {
                    if ($req->lot_qty < 13) {
                        $size = $req->lot_qty;
                    }

                    if ($req->lot_qty > 13) {
                        $size = 13;
                    }
                }

                if ($req->aql == 0.25) {
                    if ($req->lot_qty < 50) {
                        $size = $req->lot_qty;
                    }

                    if ($req->lot_qty > 50) {
                        $size = 50;
                    }
                }
            }

            if ($req->il == 'II') {
                # code...
            }
        } else {
            if ($req->il == 'S2') {
                # code...
            }

            if ($req->il == 'S3') {
                if ($req->aql == 0.40) {
                    if ($req->lot_qty <= 50) {
                        $size = $req->lot_qty;
                    }

                    if ($req->lot_qty > 50) {
                        $size = 50;
                    }
                }

                if ($req->aql == 0.25) {
                    if ($req->lot_qty < 80) {
                        $size = $req->lot_qty;
                    }

                    if ($req->lot_qty > 80) {
                        $size = 80;
                    }
                }
            }

            if ($req->il == 'II') {
                # code...
            }
        }

        return $data = [
            'sample_size' => $size,
            'accept' => $accept,
            'reject' => $reject,
            'date_inspected' => date('Y-m-d'),
            'inspector' =>Auth::user()->user_id,
            'workweek' =>$this->getWorkWeek()
        ];
    }

    public function getDropdowns()
    {
        $common = new CommonController;

        $family = $common->getDropdownByNameSelect2('Family');
        $tofinspection = $common->getDropdownByNameSelect2('Type of Inspection');
        $sofinspection = $common->getDropdownByNameSelect2('Severity of Inspection');
        $inspectionlvl = $common->getDropdownByNameSelect2('Inspection Level');
        $aql = $common->getDropdownByNameSelect2('AQL');
        $shift = $common->getDropdownByNameSelect2('Shift');
        $submission = $common->getDropdownByNameSelect2('Submission');
        $shift = $common->getDropdownByNameSelect2('Shift');
        $mod = $common->getDropdownByNameSelect2('Mode of Defect - IQC Inspection');

        return $data = [
                    'family' => $family,
                    'tofinspection' => $tofinspection,
                    'sofinspection' => $sofinspection,
                    'inspectionlvl' => $inspectionlvl,
                    'aql' => $aql,
                    'shift' => $shift,
                    'submission' => $submission,
                    'shift' => $shift,
                    'mod' => $mod,
                ];
    }

    private function checkIfExistObject($object)
    {
       return count( (array)$object);
    }

    private function array_to_object($array)
    {
        return (object) $array;
    }

    private function getWorkWeek()
    {
        $yr = 52;
        $apr = date('Y').'-04-01';
        $aprweek = date("W", strtotime($apr));

        $diff = $yr - $aprweek;
        $date = Carbon::now();
        $weeknow = $date->format("W");

        $workweek = $diff + $weeknow;
        return $workweek;
    }

    public function saveModeOfDefectsInspection(Request $req)
    {
        $data = [
            'return_status' => "failed",
            "msg" => "Mode of Defect saving failed."
        ];

        $total_mod_count = $req->current_count + $req->qty;

        if ($total_mod_count > $req->sample_size) {
            $data = [
                'return_status' => "failed",
                "msg" => "Mode of Defect quantity is more than the Sample Size.",
                "count" => $total_mod_count
            ];
        } else {
            if ($req->status == 'ADD') {
                $query = DB::connection($this->mysql)->table('tbl_mod_iqc_inspection')
                            ->insert([
                                'invoice_no' => $req->invoiceno,
                                'partcode' => $req->item,
                                'mod' => $req->mod,
                                'qty' => $req->qty,
                                'lot_no' => $req->lot_no,
                                'created_at' => Carbon::now(),
                            ]);
            } else {
                $query = DB::connection($this->mysql)->table('tbl_mod_iqc_inspection')
                            ->where('id',$req->id)
                            ->update([
                                'mod' => $req->mod,
                                'qty' => $req->qty,
                                'updated_at' => Carbon::now(),
                            ]);
            }


            if ($query == true) {
                $data = [
                    'return_status' => "success",
                    "msg" => "Mode of Defect successfully saved.",
                    "count" => $total_mod_count
                ];
            }
        } 

        return $data;
    }

    public function deleteModeOfDefectsInspection(Request $req)
    {
        $data = [
            'return_status' => "failed",
            "msg" => "Mode of Defect deleting failed."
        ];

        $query = false;

        foreach ($req->id as $key => $id) {
            $delete = DB::connection($this->mysql)->table('tbl_mod_iqc_inspection')
                        ->where('id',$id)
                        ->delete();
            if ($delete == true) {
                $query = true;
            }
        }


        if ($query == true) {
            $data = [
                'return_status' => "success",
                "msg" => "Mode of Defect successfully deleted."
            ];
        }

        return $data;
    }

    public function getModeOfDefectsInspection(Request $req)
    {
        $db = DB::connection($this->mysql)->table('tbl_mod_iqc_inspection')
                ->where('invoice_no',$req->invoiceno)
                ->where('partcode',$req->item)
                ->get();
        if ($this->checkIfExistObject($db) > 0) {
            return $db;
        }
    }

    public function getIQCData(Request $req)
    {
        $inspection =  DB::connection($this->mysql)->table('iqc_inspections')
                            ->where('judgement','<>','On-going')
                            ->orderBy('updated_at','desc')
                            ->select(['id','invoice_no','partcode','partname','supplier','app_date','app_time','app_no','lot_no','lot_qty','type_of_inspection','severity_of_inspection',
                                    'inspection_lvl','aql','accept','reject','date_ispected','ww','fy','time_ins_from','time_ins_to','shift','inspector','submission','judgement',
                                    'lot_inspected','lot_accepted','sample_size','no_of_defects','remarks']);

        return Datatables::of($inspection)
                        ->editColumn('id', function ($data) {
                            return $data->id;
                        })
                        ->editColumn('time_ins_from', function ($data) {
                            return $data->time_ins_from.' - '.$data->time_ins_to;
                        })
                        ->addColumn('action', function ($data) {
                            return '<a href="javascript:;" class="btn input-sm blue btn_editiqc" data-id="'.$data->id.'"
                                                data-invoice_no="'.$data->invoice_no.'"
                                                data-partcode="'.$data->partcode.'"
                                                data-partname="'.$data->partname.'"
                                                data-supplier="'.$data->supplier.'"
                                                data-app_date="'.$data->app_date.'"
                                                data-app_time="'.$data->app_time.'"
                                                data-app_no="'.$data->app_no.'"
                                                data-lot_no="'.$data->lot_no.'"
                                                data-lot_qty="'.$data->lot_qty.'"
                                                data-type_of_inspection="'.$data->type_of_inspection.'"
                                                data-severity_of_inspection="'.$data->severity_of_inspection.'"
                                                data-inspection_lvl="'.$data->inspection_lvl.'"
                                                data-aql="'.$data->aql.'"
                                                data-accept="'.$data->accept.'"
                                                data-reject="'.$data->reject.'"
                                                data-date_ispected="'.$data->date_ispected.'"
                                                data-ww="'.$data->ww.'"
                                                data-fy="'.$data->fy.'"
                                                data-time_ins_from="'.$data->time_ins_from.'"
                                                data-time_ins_to="'.$data->time_ins_to.'"
                                                data-shift="'.$data->shift.'"
                                                data-inspector="'.$data->inspector.'"
                                                data-submission="'.$data->submission.'"
                                                data-judgement="'.$data->judgement.'"
                                                data-lot_inspected="'.$data->lot_inspected.'"
                                                data-lot_accepted="'.$data->lot_accepted.'"
                                                data-sample_size="'.$data->sample_size.'"
                                                data-no_of_defects="'.$data->no_of_defects.'"
                                                data-remarks="'.$data->remarks.'">
                                                <i class="fa fa-edit"></i>
                                            </a>';
                        })
                        ->make(true);
    }

    public function getOngoing(Request $req)
    {
        $onGoing =  DB::connection($this->mysql)->table('iqc_inspections')
                    ->where('judgement','On-going')
                    ->orderBy('created_at','desc')
                    ->select(['id','invoice_no','partcode','partname','supplier','app_date','app_time','app_no','lot_no','lot_qty','type_of_inspection','severity_of_inspection',
                                    'inspection_lvl','aql','accept','reject','date_ispected','ww','fy','time_ins_from','time_ins_to','shift','inspector','submission','judgement',
                                    'lot_inspected','lot_accepted','sample_size','no_of_defects','remarks']);

        return Datatables::of($onGoing)
                        ->editColumn('id', function ($data) {
                            return $data->id;
                        })
                        ->addColumn('action', function ($data) {
                            return '<a href="javascript:;" class="btn input-sm blue btn_editongiong" data-id="'.$data->id.'"
                                                data-invoice_no="'.$data->invoice_no.'"
                                                data-partcode="'.$data->partcode.'"
                                                data-partname="'.$data->partname.'"
                                                data-supplier="'.$data->supplier.'"
                                                data-app_date="'.$data->app_date.'"
                                                data-app_time="'.$data->app_time.'"
                                                data-app_no="'.$data->app_no.'"
                                                data-lot_no="'.$data->lot_no.'"
                                                data-lot_qty="'.$data->lot_qty.'"
                                                data-type_of_inspection="'.$data->type_of_inspection.'"
                                                data-severity_of_inspection="'.$data->severity_of_inspection.'"
                                                data-inspection_lvl="'.$data->inspection_lvl.'"
                                                data-aql="'.$data->aql.'"
                                                data-accept="'.$data->accept.'"
                                                data-reject="'.$data->reject.'"
                                                data-date_ispected="'.$data->date_ispected.'"
                                                data-ww="'.$data->ww.'"
                                                data-fy="'.$data->fy.'"
                                                data-time_ins_from="'.$data->time_ins_from.'"
                                                data-time_ins_to="'.$data->time_ins_to.'"
                                                data-shift="'.$data->shift.'"
                                                data-inspector="'.$data->inspector.'"
                                                data-submission="'.$data->submission.'"
                                                data-judgement="'.$data->judgement.'"
                                                data-lot_inspected="'.$data->lot_inspected.'"
                                                data-lot_accepted="'.$data->lot_accepted.'"
                                                data-sample_size="'.$data->sample_size.'"
                                                data-no_of_defects="'.$data->no_of_defects.'"
                                                data-remarks="'.$data->remarks.'">
                                                <i class="fa fa-edit"></i>
                                            </a>';
                        })
                        ->make(true);
    }

    public function deleteOnGoing(Request $req)
    {
        $data = [
            'return_status' => "failed",
            "msg" => "On-going data deleting failed."
        ];

        $query = false;

        foreach ($req->id as $key => $id) {
            $iqc = DB::connection($this->mysql)->table('iqc_inspections')
                        ->where('id',$id)
                        ->first();

            if (count((array)$iqc) > 0) {
                $lot_nos = explode(',', $iqc->lot_no);

                foreach ($lot_nos as $key => $lot) {
                    $checkInv = DB::connection($this->mysql)->update(
                                        "UPDATE tbl_wbs_inventory SET iqc_status='0'
                                        WHERE invoice_no='".$iqc->invoice_no."' AND item='".$iqc->partcode."' AND lot_no='".$lot."'"
                                    );
                                    

                    $checkBatch = DB::connection($this->mysql)->update(
                                        "UPDATE tbl_wbs_material_receiving_batch SET iqc_status='0'
                                        WHERE invoice_no='".$iqc->invoice_no."' AND item='".$iqc->partcode."' AND lot_no='".$lot."'"
                                    );
                }
                // if ($checkBatch == true) {
                //     $delete = DB::connection($this->mysql)->table('iqc_inspections')
                //                 ->where('id',$id)
                //                 ->delete();
                //     $query = true;
                // }
                $query = true;
            }

        }
        


        if ($query == true) {
            $data = [
                'return_status' => "success",
                "msg" => "Inspection data successfully deleted."
            ];
        }

        return $data;
    }

    public function deleteIQCInspection(Request $req)
    {
        $data = [
            'return_status' => "failed",
            "msg" => "Inspection data deleting failed."
        ];

        $query = false;

        foreach ($req->id as $key => $id) {
            $delete = DB::connection($this->mysql)->table('iqc_inspections')
                        ->where('id',$id)
                        ->delete();
            if ($delete == true) {
                $query = true;
            }
        }


        if ($query == true) {
            $data = [
                'return_status' => "success",
                "msg" => "Inspection data successfully deleted."
            ];
        }

        return $data;
    }

    public function getItemsSearch(Request $req)
    {
        $db = DB::connection($this->mysql)->table('iqc_inspections')
                ->select('partcode as id','partcode as text')
                ->distinct()
                ->get();
        if ($this->checkIfExistObject($db) > 0) {
            return $db;
        }
    }

    public function searchInspection(Request $req)
    {
        $from_cond = '';
        $to_cond = '';
        $item_cond ='';

        if(empty($req->item))
        {
            $item_cond ='';
        } else {
            $item_cond = " AND partcode = '" . $req->item . "'";
        }

        if (!empty($req->from) && !empty($req->to)) {
            $from_cond = "AND date_ispected BETWEEN '" . $req->from . "' AND '" . $req->to . "'";
        } else {
            $from_cond = '';
            $to_cond = '';
        }

        $data = DB::connection($this->mysql)->table('iqc_inspections')
                    ->whereRaw(" 1=1 ".$item_cond.$from_cond.$to_cond)
                    ->get();
        return $data;
    }

    public function searchHistory(Request $req)
    {
        $from_cond = '';
        $to_cond = '';
        $item_cond ='';
        $lot_cond = '';
        $judge_cond = '';

        if(empty($req->item))
        {
            $item_cond ='';
        } else {
            $item_cond = " AND partcode = '" . $req->item . "'";
        }

        if (!empty($req->from) && !empty($req->to)) {
            $from_cond = "AND date_ispected BETWEEN '" . $req->from . "' AND '" . $req->to . "'";
        } else {
            $from_cond = '';
            $to_cond = '';
        }

        if(empty($req->lotno))
        {
            $lot_cond ='';
        } else {
            $lot_cond = " AND lot_no = '" . $req->lotno . "'";
        }

        if(empty($req->judgement))
        {
            $judge_cond ='';
        } else {
            $judge_cond = " AND judgement = '" . $req->judgement . "'";
        }

        $data = DB::connection($this->mysql)->table('iqc_inspections_history')
                    ->whereRaw(" 1=1 ".$item_cond.$lot_cond.$judge_cond.$from_cond.$to_cond)
                    ->get();
        return $data;
    }

    //REQUALIFICATION
    public function getItemsRequalification()
    {
        $db = DB::connection($this->mysql)->table('iqc_inspections')
                ->select('partcode as id','partcode as text')
                ->where('judgement','Accepted')
                ->distinct()
                ->get();
        if ($this->checkIfExistObject($db) > 0) {
            return $db;
        }
    }

    public function getAppNoRequalification(Request $req)
    {
        $db = DB::connection($this->mysql)->table('iqc_inspections')
                ->select('app_no as id','app_no as text')
                ->where('judgement','Accepted')
                ->where('partcode',$req->item)
                ->distinct()
                ->get();

        if ($this->checkIfExistObject($db) > 0) {
            return $db;
        }
    }

    public function getDetailsRequalification(Request $req)
    {
        $db = DB::connection($this->mysql)->table('iqc_inspections')
                ->where('judgement','Accepted')
                ->where('partcode',$req->item)
                ->where('app_no',$req->app_no)
                ->select('partname','supplier','app_date','app_time','lot_qty')
                ->distinct()
                ->first();

        $lots = DB::connection($this->mysql)->table('iqc_inspections')
                ->where('judgement','Accepted')
                ->where('partcode',$req->item)
                ->where('app_no',$req->app_no)
                ->select('lot_no')
                ->get();

        if ($this->checkIfExistObject($db) > 0 || $this->checkIfExistObject($lots) > 0) {
            $arr = [];
            foreach ($lots as $key => $lot) {
                $arr = explode(',',$lot->lot_no);
            }
            $lotnos = [];
            $lotval = [];
            foreach ($arr as $key => $x) {
                $object = json_decode(json_encode(['id'=>$x,'text'=>$x]), FALSE);
                array_push($lotnos,$object);
                array_push($lotval,$x);
            }
            return $data = [
                'details' => $db,
                'lots' => $lotnos,
                'lotval' => $lotval
            ];
        }
    }

    public function calculateLotQtyRequalification(Request $req)
    {
        $lot_qty = 0;
        if (empty($req->lot_no)) {
            return $lot_qty;
        } else {
            foreach ($req->lot_no as $key => $lot) {
                $db = DB::connection($this->wbs)->table('tbl_wbs_inventory')
                        ->select('qty as lot_qty')
                        ->where('item',$req->item)
                        ->where('wbs_mr_id',$req->app_no)
                        ->where('lot_no',$lot)
                        ->first();
                if ($this->checkIfExistObject($db) > 0) {
                    $lot_qty = $lot_qty + $db->lot_qty;
                }
            }
            return $lot_qty;
        }
    }

    public function visualInspectionRequalification(Request $req)
    {
        $db = DB::connection($this->mysql)->table('iqc_inspections')
                ->where('judgement','Accepted')
                ->where('partcode',$req->item)
                ->where('app_no',$req->app_no)
                ->get();

        if ($this->checkIfExistObject($db) > 0) {
            return $db;
        }
    }

    public function saveRequalification(Request $req)
    {
        $data = [
            'return_status' => 'failed',
            'msg' => "Saving Failed."
        ];
        $query = false;
        $status = 0;
        $kitting = 0;

        if ($req->judgement == 'Accepted') {
            $status = 1;
            $kitting = 1;
        } else {
            $status = 2;
            $kitting = 0;
        }

        if ($req->save_status == 'ADD') {
            DB::connection($this->mysql)->table('iqc_inspections_rq')
                ->insert([
                    'ctrl_no_rq' => $req->ctrlno,
                    'partcode_rq' => $req->partcode,
                    'partname_rq' => $req->partname,
                    'supplier_rq' => $req->supplier,
                    'app_date_rq' => $req->app_date,
                    'app_time_rq' => $req->app_time,
                    'app_no_rq' => $req->app_no,
                    'lot_no_rq' =>$req->lot_no,
                    'lot_qty_rq' => $req->lot_qty,
                    'date_ispected_rq' => $req->date_inspected,
                    'ww_rq' => $req->ww,
                    'fy_rq' => $req->fy,
                    'shift_rq' => $req->shift,
                    'time_ins_from_rq' => $req->time_ins_from,
                    'time_ins_to_rq' => $req->time_ins_to,
                    'inspector_rq' => $req->inspector,
                    'submission_rq' => $req->submission,
                    'judgement_rq' => $req->judgement,
                    'lot_inspected_rq' => $req->lot_inspected,
                    'lot_accepted_rq' => $req->lot_accepted,
                    'no_of_defects_rq' => $req->no_of_defects,
                    'remarks_rq' => $req->remarks,
                    'dbcon_rq' => Auth::user()->productline,
                    'created_at' => Carbon::now(),
                ]);
            if (is_string($req->lot_no)) {
                $lots = explode(',',$req->lot_no);
                $this->requalifyInventory($req->app_no,$req->partcode,$lots);
            } else {
                $this->requalifyInventory($req->app_no,$req->partcode,$req->lot_no);
            }

            $query = true;

        } else {
            DB::connection($this->mysql)->table('iqc_inspections_rq')
                ->where('id',$req->id)
                ->update([
                    'partcode_rq' => $req->partcode,
                    'partname_rq' => $req->partname,
                    'supplier_rq' => $req->supplier,
                    'app_date_rq' => $req->app_date,
                    'app_time_rq' => $req->app_time,
                    'app_no_rq' => $req->app_no,
                    'lot_no_rq' => $req->lot_no,
                    'lot_qty_rq' => $req->lot_qty,
                    'date_ispected_rq' => $req->date_inspected,
                    'ww_rq' => $req->ww,
                    'fy_rq' => $req->fy,
                    'shift_rq' => $req->shift,
                    'time_ins_from_rq' => $req->time_ins_from,
                    'time_ins_to_rq' => $req->time_ins_to,
                    'inspector_rq' => $req->inspector,
                    'submission_rq' => $req->submission,
                    'judgement_rq' => $req->judgement,
                    'lot_inspected_rq' => $req->lot_inspected,
                    'lot_accepted_rq' => $req->lot_accepted,
                    'no_of_defects_rq' => $req->no_of_defects,
                    'remarks_rq' => $req->remarks,
                    'dbcon_rq' => Auth::user()->productline,
                    'updated_at' => Carbon::now(),
                ]);

                if (is_string($req->lot_no)) {
                    $lots = explode(',',$req->lot_no);
                    $this->requalifyInventory($req->app_no,$req->partcode,$lots);
                } else {
                    $this->requalifyInventory($req->app_no,$req->partcode,$req->lot_no);
                }

            $query = true;
        }

        if ($query) {
            $data = [
                'return_status' => 'success',
                'msg' => "Successfully Saved."
            ];
        }

        return $data;
    }

    public function getRequaliData(Request $req)
    {
        return DB::connection($this->mysql)->table('iqc_inspections_rq')
                    ->take($req->row)
                    ->get();
    }

    public function deleteRequalification(Request $req)
    {
        $data = [
            'return_status' => "failed",
            "msg" => "Re-qualified data deleting failed."
        ];

        $query = false;

        foreach ($req->id as $key => $id) {
            $delete = DB::connection($this->mysql)->table('iqc_inspections_rq')
                        ->where('id',$id)
                        ->delete();
            if ($delete == true) {
                $query = true;
            }
        }


        if ($query == true) {
            $data = [
                'return_status' => "success",
                "msg" => "Re-qualified data successfully deleted."
            ];
        }

        return $data;
    }

    public function getmodeOfDefectsRequaliData(Request $req)
    {
        $db = DB::connection($this->mysql)->table('tbl_mod_iqc_rq')
                ->where('partcode',$req->item)
                ->get();
        if ($this->checkIfExistObject($db) > 0) {
            return $db;
        }
    }

    public function saveModRequalification(Request $req)
    {
        $data = [
            'return_status' => "failed",
            "msg" => "Mode of Defect saving failed."
        ];

        if ($req->status == 'ADD') {
            $query = DB::connection($this->mysql)->table('tbl_mod_iqc_rq')
                        ->insert([
                            'partcode' => $req->item,
                            'mod' => $req->mod,
                            'qty' => $req->qty,
                            'created_at' => Carbon::now(),
                        ]);
        } else {
            $query = DB::connection($this->mysql)->table('tbl_mod_iqc_rq')
                        ->where('id',$req->id)
                        ->update([
                            'mod' => $req->mod,
                            'qty' => $req->qty,
                            'updated_at' => Carbon::now(),
                        ]);
        }


        if ($query == true) {
            $data = [
                'return_status' => "success",
                "msg" => "Mode of Defect successfully saved."
            ];
        }

        return $data;
    }

    public function deleteModRequalification(Request $req)
    {
        $data = [
            'return_status' => "failed",
            "msg" => "Mode of Defect deleting failed."
        ];

        $query = false;

        foreach ($req->id as $key => $id) {
            $delete = DB::connection($this->mysql)->table('tbl_mod_iqc_rq')
                        ->where('id',$id)
                        ->delete();
            if ($delete == true) {
                $query = true;
            }
        }


        if ($query == true) {
            $data = [
                'return_status' => "success",
                "msg" => "Mode of Defect successfully deleted."
            ];
        }

        return $data;
    }

    //GROUP BY
    public function getGroupbyContent(Request $req)
    {
        if (!empty($req->field)) {
            $db = DB::connection($this->mysql)->table('iqc_inspections')
                    ->select($req->field.' as id',$req->field.' as text')
                    ->distinct()
                    ->get();
            if ($this->checkIfExistObject($db) > 0) {
                return $db;
            }
        }
    }

    public function getGroupByTable(Request $req)
    {
        return $this->IQCDatatableQuery($req,false);
    }

    public function getInspectionByDate(Request $req)
    {
        $date_inspected = '';

        if (!empty($req->from) && !empty($req->to)) {
            $date_inspected = "date_ispected BETWEEN '".$req->from."' AND '".$req->to."'";
        }

        $db = DB::connection($this->mysql)
                ->select("SELECT *
                        FROM iqc_inspections
                        WHERE 1=1 ".$date_inspected);

        if ($this->checkIfExistObject($db) > 0) {
            return $db;
        }
    }

    private function IQCDatatableQuery($req,$join)
    {
        $g1 = ''; $g2 = ''; $g3 = '';
        $g1c = ''; $g2c = ''; $g3c = '';
        $date_inspected = '';
        $groupBy = [];

        // wheres
        if (!empty($req->from) && !empty($req->to)) {
            $date_inspected = "date_ispected BETWEEN '".$req->from."' AND '".$req->to."'";
        }

        if (!empty($req->field1) && !empty($req->content1)) {
            $g1c = " AND ".$req->field1."='".$req->content1."'";
        }

        if (!empty($req->field2) && !empty($req->content2)) {
            $g2c = " AND ".$req->field2."='".$req->content2."'";
        }

        if (!empty($req->field3) && !empty($req->content3)) {
            $g3c = " AND ".$req->field3."='".$req->content3."'";
        }

        if (!empty($req->field1)) {
            $g1 = $req->field1;
            array_push($groupBy, $g1);
        }

        if (!empty($req->field2)) {
            $g2 = $req->field2;
            array_push($groupBy, $g2);
        }

        if (!empty($req->field3)) {
            $g3 = $req->field3;
            array_push($groupBy, $g3);
        }

        $grp = implode(',',$groupBy);
        // $grby = substr($grp,0,-1);
        
        $grby = "";

        if (count($groupBy) > 0) {
            $grby = " GROUP BY ".$grp;
        }
        
        if ($join == false) {
            $db = DB::connection($this->mysql)
                ->select("SELECT SUM(sample_size) AS sample_size,
                                SUM(lot_qty) AS lot_qty,
                                SUM(no_of_defects) AS no_of_defects,
                                SUM(lot_accepted) AS lot_accepted,
                                SUM(lot_inspected) AS lot_inspected,
                                supplier, app_date, date_ispected, judgement,
                                time_ins_from, time_ins_to, app_no, fy, ww, submission,
                                partcode, partname, lot_no, aql
                        FROM iqc_inspections
                        WHERE 1=1".$date_inspected.$g1c.$g2c.$g3c.$grby);
        } else {

            $db = DB::connection($this->mysql)
                ->select("SELECT a.invoice_no,a.partcode,a.partname,a.supplier,a.app_date,
                                a.app_time,a.app_no,a.lot_no,a.lot_qty,a.type_of_inspection,a.severity_of_inspection,
                                a.inspection_lvl,a.aql,a.accept,a.reject,a.date_ispected,a.ww,a.fy,a.shift,
                                a.time_ins_from,a.time_ins_to,a.inspector,a.submission,a.judgement,a.lot_inspected,
                                a.lot_accepted,a.sample_size,a.no_of_defects,a.remarks,b.mod
                        FROM iqc_inspections as a
                        LEFT JOIN tbl_mod_iqc_inspection as b ON a.invoice_no = b.invoice_no
                        WHERE 1=1".$date_inspected.$g1c.$g2c.$g3c.$grby);
        }
        

        if ($this->checkIfExistObject($db) > 0) {
            return $db;
        }
    }

    public function getIQCreport(Request $req)
    {
        $db = $this->IQCDatatableQuery($req,true);

        $html1 = '<style>
                        #data {
                          border-collapse: collapse;
                          width: 100%;
                          font-size:10px
                        }

                        #data thead td {
                          border: 1px solid black;
                          text-align: center;
                        }

                        #data tbody td {
                          border-bottom: 1px solid black;
                        }

                        #info {
                          width: 100%;
                          font-size:10px
                        }

                        #info thead td {
                          text-align: center;
                        }


                      </style>
                      <table id="info">
                        <thead>
                          <tr>
                            <td colspan="5">
                              <h2>INSPECTION RESULT RECORD</h2>
                            </td>
                          </tr>
                          <tr>
                            <td colspan="5">
                              <h4>'.date("Y/m/d").'</h4>
                            </td>
                          </tr>
                        </thead>
                        <tbody>

                        </tbody>
                      </table>


                      <table id="data">
                        <thead>
                          <tr>
                            <td>Recieving Date</td>
                            <td>Inspection Date</td>
                            <td>Inspection Time</td>
                            <td>Application Ctrl#</td>
                            <td>Application Time</td>
                            <td>FY</td>
                            <td>WW</td>
                            <td>Sub</td>
                            <td>Partcode</td>
                            <td>Partname</td>
                            <td>Supplier</td>
                            <td>Lot No</td>
                            <td>Lot Quantity</td>
                            <td>AQL</td>
                            <td>Severity of Inspection</td>
                            <td>Sample Size</td>
                            <td>Inspection Result</td>
                            <td>Mode of Defects</td>
                            <td>No. of Defects</td>
                            <td>Remarks</td>
                          </tr>
                        </thead>
                        <tbody>';

            $html3 = '</tbody>
                      </table>';

            $html2 = '';
            foreach ($db as $key => $x) {
                $html2 .= '<tr>
                    <td>'.$x->app_date.'</td>
                    <td>'.$x->date_ispected.'</td>
                    <td>'.$x->time_ins_from.'</td>
                    <td>'.$x->app_no.'</td>
                    <td>'.$x->app_time.'</td>
                    <td>'.$x->fy.'</td>
                    <td>'.$x->ww.'</td>
                    <td>'.$x->submission.'</td>
                    <td>'.$x->partcode.'</td>
                    <td>'.$x->partname.'</td>
                    <td>'.$x->supplier.'</td>
                    <td>'.$x->lot_no.'</td>
                    <td>'.$x->lot_qty.'</td>
                    <td>'.$x->aql.'</td>
                    <td>'.$x->severity_of_inspection.'</td>
                    <td>'.$x->sample_size.'</td>
                    <td>'.$x->judgement.'</td>
                    <td>'.$x->mod.'</td>
                    <td>'.$x->no_of_defects.'</td>
                    <td>'.$x->remarks.'</td>
                </tr>';
            }

            $html_final = $html1.$html2.$html3;
            $dompdf = new Dompdf();
            $dompdf->loadHtml($html_final);
            $dompdf->setPaper('letter', 'landscape');
            $dompdf->render();
            $dompdf->stream('IQC_Inspection_'.Carbon::now().'.pdf');
    }

    public function getIQCreportexcel(Request $req)
    {
        try
        {
            $dt = Carbon::now();
            $date = substr($dt->format('Ymd'), 2);

            Excel::create('IQC_Inspection_Report'.$date, function($excel) use($req)
            {
                $excel->sheet('Sheet1', function($sheet) use($req)
                {

                    $dt = Carbon::now();
                    $date = $dt->format('Y-m-d');

                    $sheet->setCellValue('A1', 'INSPECTION RECORD RESULT');
                    $sheet->mergeCells('A1:O1');

                    $sheet->cell('B5',"RECEIVING DATE");
                    $sheet->cell('C5',"INSPECTION DATE");
                    $sheet->cell('D5',"INSPECTION TIME");
                    $sheet->cell('E5',"APPLICATION CTRL#");
                    $sheet->cell('F5',"APPLICATION TIME");
                    $sheet->cell('G5',"FY");
                    $sheet->cell('H5',"WW");
                    $sheet->cell('I5',"SUBMISSION");
                    $sheet->cell('J5',"PART CODE");
                    $sheet->cell('K5',"PART NAME");
                    $sheet->cell('L5',"SUPPLIER");
                    $sheet->cell('M5',"LOT NO");
                    $sheet->cell('N5',"LOT QUANTITY");
                    $sheet->cell('O5',"AQL");
                    $sheet->cell('P5',"SEVERITY OF INSPECTION");
                    $sheet->cell('Q5',"SAMPLE SIZE");
                    $sheet->cell('R5',"INSPECTION RESULT");
                    $sheet->cell('S5',"NO OF DEFECTS");
                    $sheet->cell('T5',"REMARKS");
                    $sheet->cell('Q3',$date);
                    $sheet->setHeight(1,30);
                    $sheet->row(1, function ($row) {
                        $row->setFontFamily('Calibri');
                        $row->setBackground('#ADD8E6');
                        $row->setFontSize(15);
                        $row->setAlignment('center');
                    });
                    $sheet->row(5, function ($row) {
                        $row->setFontFamily('Calibri');
                        $row->setBackground('#ADD8E6');
                        $row->setFontSize(10);
                        $row->setAlignment('center');
                    });
                    $sheet->setStyle(array(
                        'font' => array(
                            'name'  =>  'Calibri',
                            'size'  =>  10
                        )
                    ));

                    $row = 6;
                    $field='';

                    $db = $this->IQCDatatableQuery($req,true);

                    foreach ($db as $key => $x) {
                        $sheet->cell('B'.$row, $x->app_date);
                        $sheet->cell('C'.$row, $x->date_ispected);
                        $sheet->cell('D'.$row, $x->time_ins_from);
                        $sheet->cell('E'.$row, $x->app_no);
                        $sheet->cell('F'.$row, $x->app_time);
                        $sheet->cell('G'.$row, $x->fy);
                        $sheet->cell('H'.$row, $x->ww);
                        $sheet->cell('I'.$row, $x->submission);
                        $sheet->cell('J'.$row, $x->partcode);
                        $sheet->cell('K'.$row, $x->partname);
                        $sheet->cell('L'.$row, $x->supplier);
                        $sheet->cell('M'.$row, $x->lot_no);
                        $sheet->cell('N'.$row, $x->lot_qty);
                        $sheet->cell('O'.$row, $x->aql);
                        $sheet->cell('P'.$row, $x->severity_of_inspection);
                        $sheet->cell('Q'.$row, $x->sample_size);
                        $sheet->cell('R'.$row, $x->judgement);
                        $sheet->cell('S'.$row, $x->no_of_defects);
                        $sheet->cell('T'.$row, $x->remarks);
                        $row++;
                    }

                });

            })->download('xls');
        } catch (Exception $e) {
            return redirect(url('/iqcinspection'))->with(['err_message' => $e]);
        }
    }

    public function uploadfiles(Request $req)
    {
        $inspection_data = $req->file('inspection_data');
        $inspection_mod = $req->file('inspection_mod');
        $requali_data = $req->file('requali_data');
        $requali_mod = $req->file('requali_mod');

        $data = [
            'return_status' => 'failed',
            'msg' => 'Upload was unsuccessful.'
        ];

        $process = false;

        if (isset($inspection_data)) {
            $this->uploadInspection($inspection_data);
            $process = true;
        }

        if (isset($inspection_mod)) {
            $this->uploadInspectionMod($inspection_mod);
            $process = true;
        }

        if (isset($requali_data)) {
            $process = true;
        }

        if (isset($requali_mod)) {
            $process = true;
        }

        if ($process == true) {
            $data = [
                'return_status' => 'success',
                'msg' => 'Data were successfully uploaded.'
            ];
        }

        return $data;
    }

    private function uploadInsepectionInsert($field)
    {
        $status = 0;
        $kitting = 0;

        if ($field['judgement'] == 'Accepted') {
            $status = 1;
            $kitting = 1;
        } else {
            $status = 2;
            $kitting = 0;
        }

        $lot_qty = $this->getLotQty($field['invoice_no'],$field['partcode'],$field['lot_no']);
        
        DB::connection($this->mysql)->table('iqc_inspections')
            ->insert([
                'invoice_no' => $field['invoice_no'],
                'partcode' => $field['partcode'],
                'partname' => $field['partname'],
                'supplier' => $field['supplier'],
                'app_date' => $field['app_date'],
                'app_time' => $field['app_time'],
                'app_no' => $field['app_no'],
                'lot_no' => $field['lot_no'],
                'lot_qty' => $lot_qty,
                'type_of_inspection' => $field['type_of_inspection'],
                'severity_of_inspection' => $field['severity_of_inspection'],
                'inspection_lvl' => $field['inspection_lvl'],
                'aql' => $field['aql'],
                'accept' => $field['accept'],
                'reject' => $field['reject'],
                'date_ispected' => $field['date_inspected'],
                'ww' => $field['ww'],
                'fy' => $field['fy'],
                'shift' => $field['shift'],
                'time_ins_from' => $field['time_inspection_from'],
                'time_ins_to' => $field['time_inspection_to'],
                'inspector' => $field['inspector'],
                'submission' => $field['submission'],
                'judgement' => $field['judgement'],
                'lot_inspected' => $field['lot_inspected'],
                'lot_accepted' => $field['lot_accepted'],
                'sample_size' => $field['sample_size'],
                'no_of_defects' => $field['no_of_defects'],
                'remarks' => $field['remarks'],
                'dbcon' => Auth::user()->productline,
                'created_at' => Carbon::now(),
            ]);

        DB::connection($this->mysql)->table('iqc_inspections_history')
                ->insert([
                    'invoice_no' => $field['invoice_no'],
                    'partcode' => $field['partcode'],
                    'partname' => $field['partname'],
                    'supplier' => $field['supplier'],
                    'app_date' => $field['app_date'],
                    'app_time' => $field['app_time'],
                    'app_no' => $field['app_no'],
                    'lot_no' => $field['lot_no'],
                    'lot_qty' => $lot_qty,
                    'type_of_inspection' => $field['type_of_inspection'],
                    'severity_of_inspection' => $field['severity_of_inspection'],
                    'inspection_lvl' => $field['inspection_lvl'],
                    'aql' => $field['aql'],
                    'accept' => $field['accept'],
                    'reject' => $field['reject'],
                    'date_ispected' => $field['date_inspected'],
                    'ww' => $field['ww'],
                    'fy' => $field['fy'],
                    'shift' => $field['shift'],
                    'time_ins_from' => $field['time_inspection_from'],
                    'time_ins_to' => $field['time_inspection_to'],
                    'inspector' => $field['inspector'],
                    'submission' => $field['submission'],
                    'judgement' => $field['judgement'],
                    'lot_inspected' => $field['lot_inspected'],
                    'lot_accepted' => $field['lot_accepted'],
                    'sample_size' => $field['sample_size'],
                    'no_of_defects' => $field['no_of_defects'],
                    'remarks' => $field['remarks'],
                    'dbcon' => Auth::user()->productline,
                    'created_at' => Carbon::now(),
                ]);

        DB::connection($this->wbs)->table('tbl_wbs_material_receiving_batch')
            ->where('invoice_no', $field['invoice_no'])
            ->where('wbs_mr_id', $field['app_no'])
            ->where('item', $field['partcode'])
            ->where('lot_no', $field['lot_no'])
            ->update([
                'iqc_status' => $status,
                'for_kitting' => $kitting,
                'iqc_result' => $field['remarks']
            ]);

        DB::connection($this->wbs)->table('tbl_wbs_inventory')
            ->where('invoice_no', $field['invoice_no'])
            ->where('wbs_mr_id', $field['app_no'])
            ->where('item', $field['partcode'])
            ->where('lot_no', $field['lot_no'])
            ->update([
                'iqc_status' => $status,
                'for_kitting' => $kitting,
                'iqc_result' => $field['remarks']
            ]);
    }

    private function uploadInspection($inspection_data)
    {
        Excel::load($inspection_data, function($reader) {

            $results = $reader->get();
            $fields = $results->toArray();

            foreach ($fields as $key => $field) {
                if ($this->ItemInspectionExists($field['invoice_no'],$field['partcode'],$field['lot_no']) < 1) {
                    $this->uploadInsepectionInsert($field);
                }
            }
        });
    }

    private function uploadInspectionMod($inspection_mod)
    {
        Excel::load($inspection_mod, function($reader) {

            $results = $reader->get();
            $fields = $results->toArray();

            foreach ($fields as $key => $field) {
                if ($this->ItemInspectionModExists($field['invoice_no'],$field['partcode'],$field['lot_no'],$field['mod']) < 1) {
                    $this->insertInspectionMod($field);
                } else {
                    $this->updateInspectionMod($field);
                }
            }
        });
    }

    private function insertInspectionMod($field)
    {
        DB::connection($this->mysql)->table('tbl_mod_iqc_inspection')
            ->insert([
                'invoice_no' => $field['invoice_no'],
                'partcode' => $field['partcode'],
                'mod' => $field['mod'],
                'qty' => $field['qty'],
                'lot_no' => $field['lot_no'],
                'created_at' => Carbon::now(),
            ]);
    }

    private function updateInspectionMod($field)
    {
        $oldmod = DB::connection($this->mysql)->table('tbl_mod_iqc_inspection')
                    ->select('qty')
                    ->where('invoice_no', $field['invoice_no'])
                    ->where('partcode', $field['partcode'])
                    ->where('mod', $field['mod'])
                    ->where('lot_no', $field['lot_no'])
                    ->first();

        $newqty = $oldmod + $field['qty'];

        DB::connection($this->mysql)->table('tbl_mod_iqc_inspection')
            ->where('invoice_no', $field['invoice_no'])
            ->where('partcode', $field['partcode'])
            ->where('mod', $field['mod'])
            ->where('lot_no', $field['lot_no'])
            ->update([
                'qty' => $newqty,
                'updated_at' => Carbon::now(),
            ]);
    }

    private function uploadRequali($requali_data)
    {
        
    }

    private function uploadRequaliMod($requali_mod)
    {
        
    }

    private function ItemInspectionExists($invoiceno,$partcode,$lotno)
    {
        $cnt = DB::connection($this->mysql)->table('iqc_inspections')
                    ->where('invoice_no',$invoiceno)
                    ->where('partcode',$partcode)
                    ->where('lot_no','like','%'.$lotno.'%')
                    ->count();
        return $cnt;
    }

    private function ItemInspectionModExists($invoiceno,$partcode,$lotno,$mod)
    {
        $cnt = DB::connection($this->mysql)->table('tbl_mod_iqc_inspection')
                    ->where('invoice_no',$invoiceno)
                    ->where('partcode',$partcode)
                    ->where('lot_no',$lotno)
                    ->where('mod',$mod)
                    ->count();
        return $cnt;
    }
}
