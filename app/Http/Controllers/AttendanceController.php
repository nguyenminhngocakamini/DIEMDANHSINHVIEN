<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Attendance;
use App\Models\Course;
use Carbon\Carbon;
use MongoDB\BSON\ObjectId;

class AttendanceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth'); // Chỉ người đã đăng nhập mới được xem
    }

    /**
     * Danh sách các lớp học sinh viên đã đăng ký.
     */
    public function myCourses()
    {
        $user = Auth::user();

        // Giả sử user có relation 'courses' trả về các lớp đã đăng ký
        $courses = $user->courses()->orderBy('name')->get();

        return view('student.attendance.courses', compact('courses'));
    }

    /**
     * Xem lịch sử điểm danh của sinh viên, có thể lọc theo lớp học.
     */
    public function myAttendance(Request $request)
    {
        $user = Auth::user();

        $query = Attendance::where('student_id', new ObjectId($user->_id));

        if ($request->filled('course_id')) {
            $query->where('course_id', new ObjectId($request->input('course_id')));
        }

        $attendances = $query->orderBy('attendance_date', 'desc')->get();

        return view('student.attendance.my', compact('attendances'));
    }
}
