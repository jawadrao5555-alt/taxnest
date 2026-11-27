<?php

use App\Http\Controllers\Hr\HrAuthController;
use App\Http\Controllers\Hr\HrFieldDutyController;
use App\Http\Controllers\Hr\HrLeaveController;
use App\Http\Controllers\Hr\HrPayrollController;
use App\Http\Controllers\Hr\HrPolicyController;
use App\Http\Controllers\Hr\HrProvisioningController;
use App\Http\Controllers\Hr\HrWorkspaceController;
use App\Http\Middleware\HrAuthenticate;
use Illuminate\Support\Facades\Route;

Route::prefix('hr')->name('hr.')->group(function () {
    Route::redirect('/', '/hr/dashboard');
    Route::get('login', [HrAuthController::class, 'show'])->name('login');
    Route::post('login', [HrAuthController::class, 'login'])->middleware('throttle:hr-login')->name('login.store');
    Route::post('logout', [HrAuthController::class, 'logout'])->name('logout');

    Route::middleware(HrAuthenticate::class)->controller(HrWorkspaceController::class)->group(function () {
        Route::get('dashboard', 'dashboard')->name('dashboard');
        Route::prefix('workspaces/{organization}')->whereNumber('organization')->group(function () {
            Route::get('/', 'show')->name('organizations.show');
            Route::post('employees', 'createEmployee')->name('employees.store');
            Route::patch('employees/{employee}', 'updateEmployee')->whereNumber('employee')->name('employees.update');
            Route::post('shifts', 'assignShift')->name('shifts.store');
            Route::post('shifts/{shift}/punch', 'punch')->whereNumber('shift')->middleware('throttle:hr-attendance')->name('attendance.punch');
            Route::get('policy', [HrPolicyController::class, 'show'])->name('policy.show');
            Route::post('policy', [HrPolicyController::class, 'store'])->name('policy.store');
            Route::get('leave', [HrLeaveController::class, 'index'])->name('leave.index');
            Route::post('leave', [HrLeaveController::class, 'store'])->name('leave.store');
            Route::patch('leave/{leave}/review', [HrLeaveController::class, 'review'])->whereNumber('leave')->name('leave.review');
            Route::patch('leave/{leave}/cancel', [HrLeaveController::class, 'cancel'])->whereNumber('leave')->name('leave.cancel');
            Route::get('payslips', [HrPayrollController::class, 'ownPayslips'])->name('payroll.own');
            Route::get('payslips/{period}/{line}', [HrPayrollController::class, 'ownPayslip'])->whereNumber(['period', 'line'])->name('payroll.own.show');
            Route::prefix('payroll')->name('payroll.')->controller(HrPayrollController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::prefix('{period}')->whereNumber('period')->group(function () {
                    Route::get('/', 'show')->name('show');
                    Route::post('lines', 'saveLine')->name('lines.store');
                    Route::delete('lines/{line}', 'removeLine')->whereNumber('line')->name('lines.remove');
                    Route::post('approve', 'approve')->name('approve');
                    Route::get('payslips/{line}', 'payslip')->whereNumber('line')->name('payslip');
                    Route::get('export', 'export')->name('export');
                });
            });
            Route::get('field-duty', [HrFieldDutyController::class, 'index'])->name('field-duty.index');
            Route::get('shifts/{shift}/field-duty', [HrFieldDutyController::class, 'create'])->whereNumber('shift')->name('field-duty.create');
            Route::post('shifts/{shift}/field-duty', [HrFieldDutyController::class, 'store'])->whereNumber('shift')->name('field-duty.store');
            Route::patch('field-duty/{fieldDuty}/review', [HrFieldDutyController::class, 'review'])->whereNumber('fieldDuty')->name('field-duty.review');
        });
    });
});

Route::prefix('admin/hr-workspaces')->middleware('admin.auth')->name('admin.hr.')->controller(HrProvisioningController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'create')->name('store');
    Route::get('{organization}/members', 'members')->whereNumber('organization')->name('members.index');
    Route::post('{organization}/members', 'addMember')->whereNumber('organization')->name('members.store');
    Route::patch('{organization}/members/{member}', 'changeMembership')->whereNumber(['organization', 'member'])->name('members.update');
});
