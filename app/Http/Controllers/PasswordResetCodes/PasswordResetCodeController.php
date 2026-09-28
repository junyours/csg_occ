<?php

namespace App\Http\Controllers\PasswordResetCodes;

use App\Application\Actions\PasswordResetCodes\BuildPasswordResetCodeList;
use App\Application\Actions\PasswordResetCodes\DeleteDeadPasswordResetCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordResetCodes\DestroyPasswordResetCodesRequest;
use App\Http\Requests\PasswordResetCodes\IndexPasswordResetCodeRequest;

class PasswordResetCodeController extends Controller
{
    public function index(IndexPasswordResetCodeRequest $request, BuildPasswordResetCodeList $buildPasswordResetCodeList)
    {
        return response()->json($buildPasswordResetCodeList($request->user()));
    }

    /**
     * "Clear used & expired". Answers with the fresh list (same shape as
     * index) plus how many rows went, so the screen updates from this one
     * response instead of clearing and then making a second call to reload.
     */
    public function destroy(
        DestroyPasswordResetCodesRequest $request,
        DeleteDeadPasswordResetCodes $deleteDeadPasswordResetCodes,
        BuildPasswordResetCodeList $buildPasswordResetCodeList,
    ) {
        $deleted = $deleteDeadPasswordResetCodes($request->user());

        return response()->json([
            ...$buildPasswordResetCodeList($request->user()),
            'deleted' => $deleted,
        ]);
    }
}
