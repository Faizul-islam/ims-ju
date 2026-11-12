<?php

namespace App\Http\Controllers\Inventory;

use DB;
use Auth;
use Session;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Inventory\Stock;
use App\Http\Controllers\Controller;
use App\Models\Inventory\RequestItem;
use App\Models\Inventory\InvItemInformation;
use App\Models\Inventory\RequestItemApprovalHistory;
use App\Models\Inventory\RequestItemDetails;

class RequestItemController extends Controller
{
    public function index(Request $request)
    {
        $itemsPerPage = itemsPerPage();
        $_args = array(
            'items_per_page' => $itemsPerPage,
            'paginate'       => true
        );

        $_args = filterParams(
            $_args,
            array(
                'request_from',
                'request_to',
            )
        );

        $data = RequestItem::getMasterData();
        $data['item_receive_from_supplier_information'] = RequestItem::getAll($_args);
        $data['itemsPerPage'] = $itemsPerPage;

        return view('inventory.item-request.index', $data);
    }

    public function create()
    {
        $data = RequestItem::getMasterData();
        $data['locationId'] = !empty(auth()->user()->location_id) ? auth()->user()->location_id : null;
        $data['requestDate'] = date('d-m-Y');
        return view('inventory.item-request.create', $data);
    }

    public function store(Request $request)
    {
        // return $request->all();
        try {
            DB::beginTransaction();
            RequestItem::saveOrUpdate($request);
            DB::commit();
            Session::flash('success', __('Saved Successfully!'));

            // Redirect to edit mode.
            return redirect(action('Inventory\RequestItemController@create'));
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->withErrors($e->getMessage())
                ->withInput($request->all);
        }
    }

    public function edit($id)
    {
        $requestedItem = RequestItem::findOrFail($id);
        $data = RequestItem::getMasterData($requestedItem);
        $data['requestedItem'] = $requestedItem;
        $data['itemDetails'] = $requestedItem->itemDetails()->get();

        return view('inventory.item-request.edit', $data);
    }

    public function show($id)
    {
        $requestedItem = RequestItem::findOrFail($id);
        $data = RequestItem::getMasterData($requestedItem);
        $data['requestedItem'] = $requestedItem;
        $data['itemDetails'] = $requestedItem->itemDetails()->get();
        $data['approvalHistory'] = RequestItemApprovalHistory::where('request_item_id', $id)->get();
        return view('inventory.item-request.show', $data);
    }

    public function approveItem($id)
    {
        $requestedItem = RequestItem::findOrFail($id);
        $data = RequestItem::getMasterData($requestedItem);
        $data['requestedItem'] = $requestedItem;
        $data['itemDetails'] = $requestedItem->itemDetails()->get();
        $data['type'] = 'Approve';
        $data['userLists'] = User::getUserListWithDesignation();
        $data['approvalHistory'] = RequestItemApprovalHistory::where('request_item_id', $id)->get();
        return view('inventory.item-request.show', $data);
    }

    public function createReceive($id)
    {
        $requestedItem = RequestItem::findOrFail($id);
        $data = RequestItem::getMasterData($requestedItem);
        $data['requestedItem'] = $requestedItem;
        $data['itemDetails'] = $requestedItem->itemDetails()->get();
        $data['type'] = 'Receive';
        $data['approvalHistory'] = RequestItemApprovalHistory::where('request_item_id', $id)->get();
        return view('inventory.item-request.show', $data);
    }

    public function approve(Request $request, $id)
    {
        $requestedItem = RequestItem::findOrFail($id);
        $isApproval = $request->input('status') === 'Approve';
        $canApprove = hasUserCap('approve_item_request_information');
        $isRequester = $requestedItem->requested_by == Auth::id();

        // This route handles both steps: approvers approve or forward, and
        // approvers or the requester receive.
        if (!$canApprove && ($isApproval || !$isRequester)) {
            return response('Sorry, You are Unauthorized. Access Denied!', 401);
        }

        // Receiving moves stock, so it has to follow approval and happen only once.
        if (!$isApproval && (!$requestedItem->is_approved || $requestedItem->is_received)) {
            return redirect()->back()
                ->withErrors(__('This request is not ready to be received.'));
        }

        try {
            DB::beginTransaction();
            $requestData = $request->all();
            // Only these columns may change here; the rest of the input is not trusted.
            $workflowData = [];
            if($isApproval) {
                $workflowData['forwarded_user_id'] = $request->input('forwarded_user_id');

                if($requestData['approve_type'] == 'approved') {
                    $workflowData['approved_date'] = date('Y-m-d');
                    $workflowData['is_approved'] = 1;
                    $workflowData['approved_by'] = Auth::id();
                    $message = __('Approved Successfully!');
                } else {
                    $message = __('Forwarded Successfully!');
                }
                RequestItemApprovalHistory::create([
                    'request_item_id' => $id,
                    'user_id' => Auth::id(),
                    'type' => $requestData['approve_type'],
                    'comments' => $requestData['comments']
                ]);
                foreach($requestData['serial'] as $id=> $serial) {
                    $itemDetails = RequestItemDetails::find($id);
                    $itemDetails->update(['serial_no'=>$serial]);
                }


            } else {
                $workflowData['received_date'] = date('Y-m-d');
                $workflowData['is_received'] = 1;
                $workflowData['received_by'] = Auth::id();
                $message = __('Received Successfully!');
                $this->updateStock($id);
            }
            $requestedItem->update($workflowData);
            DB::commit();
            Session::flash('success', $message);
            return redirect(action('Inventory\RequestItemController@index'));
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->withErrors($e->getMessage())
                ->withInput($request->all);
        }
    }

    public function updateStock($requestItemMasterId)
    {
        $requestedItem = RequestItem::findOrFail($requestItemMasterId);
        $items = RequestItemDetails::where('request_item_master_id', $requestItemMasterId)->get();
        // First decrese stock for all item
        foreach($items as $item)
        {
            $items = [
                'item_id' => $item->item_id,
                'item_status_id' => 1,  // 1 for item status good
                'quantity' => $item->quantity,
                'serial' => $item->serial,
                'user_id' => null,
                'dept' => 'dept_cse',
            ];
            Stock::remove($items);
            $items['user_id'] = $requestedItem->requested_by;
            $items['asset_location_id'] = $requestedItem->location_id;
            Stock::add($items);
        }
    }

    public function update(Request $request, $id)
    {

        try {
            DB::beginTransaction();
            RequestItem::saveOrUpdate($request, $id);
            DB::commit();
            Session::flash('success', __('Updated Successfully!'));
            return back();
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->withErrors($e->getMessage())
                ->withInput($request->all);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            RequestItemDetails::where('request_item_master_id', $id)->delete();
            RequestItem::destroy($id);

            DB::commit();

            return redirect()->back()->with('success', __('Deleted Successfully!'));
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                ->withErrors($e->getMessage())
                ->withInput();
        }
    }

    public function getItemByCategory($categoryId)
    {
        $items = InvItemInformation::where('category_id', $categoryId)->pluck('name_en', 'id');
        return response()->json($items);
    }
}
