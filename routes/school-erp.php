<?php

use App\Http\Controllers\PackageCheckoutController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\SchoolErpAttendanceController;
use App\Http\Controllers\SchoolErpAuthController;
use App\Http\Controllers\SchoolErpGradebookController;
use App\Http\Controllers\SchoolErpWorkspaceController;
use App\Http\Middleware\SchoolErpAccess;
use App\Support\SchoolErpPanel;
use Illuminate\Support\Facades\Route;

Route::prefix(SchoolErpPanel::PREFIX)->name('school-erp.')->group(function () {
    Route::get('/login', [SchoolErpAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [SchoolErpAuthController::class, 'login'])->middleware('throttle:6,1')->name('login.submit');
    Route::get('/register', [SchoolErpAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [SchoolErpAuthController::class, 'register'])->middleware('throttle:4,1')->name('register.submit');
    Route::post('/language', [SchoolErpAuthController::class, 'language'])->name('language');
    Route::post('/logout', [SchoolErpAuthController::class, 'logout'])->name('logout');

    Route::middleware(SchoolErpAccess::class)->group(function () {
        Route::get('/', fn () => redirect()->route('school-erp.dashboard'))->name('home');
        Route::get('/dashboard', [SchoolErpWorkspaceController::class, 'index'])->name('dashboard');
        Route::get('/package-checkout', [PackageCheckoutController::class, 'index'])->name('package-checkout');
        Route::post('/package-checkout', [PackageCheckoutController::class, 'select'])->name('package-checkout.select');
        Route::post('/package-payment-proof', [PaymentProofController::class, 'store'])->middleware('throttle:6,1')->name('payment-proof.store');
        Route::post('/campuses', [SchoolErpWorkspaceController::class, 'campus'])->name('campuses.store');
        Route::post('/students', [SchoolErpWorkspaceController::class, 'student'])->name('students.store');
        Route::post('/terms', [SchoolErpWorkspaceController::class, 'term'])->name('terms.store');
        Route::get('/attendance', [SchoolErpAttendanceController::class, 'index'])->name('attendance');
        Route::post('/attendance', [SchoolErpAttendanceController::class, 'store'])->name('attendance.store');
        Route::get('/gradebook', [SchoolErpGradebookController::class, 'index'])->name('gradebook');
        Route::post('/gradebook/classes', [SchoolErpGradebookController::class, 'storeClass'])->name('gradebook.classes.store');
        Route::post('/gradebook/classes/{class}/students', [SchoolErpGradebookController::class, 'storeStudent'])
            ->whereNumber('class')->name('gradebook.students.store');
        Route::post('/gradebook/classes/{class}/assessments', [SchoolErpGradebookController::class, 'storeAssessment'])
            ->whereNumber('class')->name('gradebook.assessments.store');
        Route::post('/gradebook/classes/{class}/grades', [SchoolErpGradebookController::class, 'storeGrade'])
            ->whereNumber('class')->name('gradebook.grades.store');
        Route::post('/admissions', [SchoolErpWorkspaceController::class, 'admit'])->name('admissions.store');
        Route::post('/students/{student}/transfer', [SchoolErpWorkspaceController::class, 'transfer'])
            ->whereNumber('student')->name('students.transfer');
        Route::post('/staff', [SchoolErpWorkspaceController::class, 'staff'])->name('staff.store');
    });
});
