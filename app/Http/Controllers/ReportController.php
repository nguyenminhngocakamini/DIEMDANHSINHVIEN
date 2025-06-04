<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!Gate::allows('view-attendance-reports')) {
                abort(403);
            }
            return $next($request);
        });
    }

    public function attendanceReport(Request $request)
    {
        $courses = Course::orderBy('name')->get();
        $students = User::where('role', 'sinhvien')->orderBy('name')->get();

        // Lấy khoảng thời gian (mặc định tháng hiện tại)
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->startOfMonth();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now()->endOfMonth();

        // Tạo pipeline aggregation MongoDB
        $pipeline = [
            [
                '$match' => [
                    'attendance_date' => [
                        '$gte' => new UTCDateTime($startDate->copy()->startOfDay()->getTimestamp() * 1000),
                        '$lte' => new UTCDateTime($endDate->copy()->endOfDay()->getTimestamp() * 1000),
                    ],
                ],
            ],
        ];

        // Nếu có lọc theo môn học thì thêm điều kiện
        if ($request->filled('course_id')) {
            $pipeline[] = [
                '$match' => ['course_id' => new ObjectId($request->input('course_id'))],
            ];
        }

        // Nhóm dữ liệu
        $pipeline[] = [
            '$group' => [
                '_id' => [
                    'student_id' => '$student_id',
                    'course_id' => '$course_id',
                ],
                'total_sessions' => ['$sum' => 1],
                'present_sessions' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'present']], 1, 0]]],
                'absent_sessions' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'absent']], 1, 0]]],
                'late_sessions' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'late']], 1, 0]]],
                'permitted_absence_sessions' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'permitted_absence']], 1, 0]]],
            ],
        ];

        // Lookup thông tin sinh viên
        $pipeline[] = [
            '$lookup' => [
                'from' => 'users',
                'localField' => '_id.student_id',
                'foreignField' => '_id',
                'as' => 'student_info',
            ],
        ];
        $pipeline[] = ['$unwind' => '$student_info'];

        // Lookup thông tin môn học
        $pipeline[] = [
            '$lookup' => [
                'from' => 'courses',
                'localField' => '_id.course_id',
                'foreignField' => '_id',
                'as' => 'course_info',
            ],
        ];
        $pipeline[] = ['$unwind' => '$course_info'];

        // Projection dữ liệu trả về
        $pipeline[] = [
            '$project' => [
                '_id' => 0,
                'student_id' => '$_id.student_id',
                'student_name' => '$student_info.name',
                'student_code' => '$student_info.student_id_code',
                'course_id' => '$_id.course_id',
                'course_name' => '$course_info.name',
                'course_code' => '$course_info.code',
                'total_sessions' => 1,
                'present_sessions' => 1,
                'absent_sessions' => 1,
                'late_sessions' => 1,
                'permitted_absence_sessions' => 1,
                'attendance_rate' => [
                    '$cond' => [
                        'if' => ['$gt' => ['$total_sessions', 0]],
                        'then' => [
                            '$multiply' => [
                                [
                                    '$divide' => [
                                        ['$add' => ['$present_sessions', '$late_sessions', '$permitted_absence_sessions']],
                                        '$total_sessions',
                                    ],
                                ],
                                100,
                            ],
                        ],
                        'else' => 0,
                    ],
                ],
            ],
        ];

        // Sắp xếp theo tên môn và tên sinh viên
        $pipeline[] = ['$sort' => ['course_name' => 1, 'student_name' => 1]];

        // Thực thi aggregate và lấy kết quả
        $statsCursor = Attendance::raw(fn($collection) => $collection->aggregate($pipeline));
        $stats = collect(iterator_to_array($statsCursor));

        // Trả về view với dữ liệu
        return view('reports.attendance', compact('stats', 'courses', 'students', 'startDate', 'endDate', 'request'));
    }
}
