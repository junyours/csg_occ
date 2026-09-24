<?php

namespace App\Http\Controllers\Students;

use App\Application\Actions\Students\GenerateStudentQrCode;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class StudentQrController extends Controller
{
    public function show(
    Request $request,
    Student $student,
    GenerateStudentQrCode $generateStudentQrCode
): Response {
    Gate::authorize('viewQr', $student);

    // $etag = sprintf('"qr-%d-v%d"', $student->id, $student->qr_version);
        // Include a build marker so changing how the PNG is rendered (size,
    // margin, error-correction level) invalidates every client's cached
    // copy immediately, without needing to bump every student's
    // qr_version — that field means something different (a deliberate
    // reissue) and shouldn't be conflated with "we changed the renderer."
    $etag = sprintf('"qr-%d-v%d-r2"', $student->id, $student->qr_version);

    if ($request->headers->get('If-None-Match') === $etag) {
        return response('', 304, ['ETag' => $etag]);
    }

    $png = $generateStudentQrCode($student);

    $disposition = $request->boolean('download')
        ? sprintf('attachment; filename="qr-%s.png"', $student->student_number)
        : 'inline';

    return response($png, 200, [
        'Content-Type' => 'image/png',
        'Content-Disposition' => $disposition,
        'Cache-Control' => 'private, max-age=604800',
        'ETag' => $etag,
    ]);
}
}
