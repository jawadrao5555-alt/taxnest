<?php
namespace Tests\Feature;
use App\Models\{AdminUser,User,SecurityLog};
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class SecurityLoginActorIsolationTest extends TestCase
{
    use RefreshDatabase;
    public function test_admin_login_without_matching_user_never_uses_user_foreign_key():void
    {
        $id=(int)DB::table('users')->max('id')+10000;
        $admin=new AdminUser(['name'=>'Synthetic owner','email'=>'native-guard@example.test','password'=>'SyntheticOnly123!','role'=>'super_admin']);$admin->id=$id;$admin->save();
        $this->assertNull(User::find($id));auth('admin')->login($admin);
        $row=SecurityLog::where('action','login')->latest('id')->firstOrFail();
        $this->assertNull($row->user_id);$this->assertSame('saas_admin',$row->metadata['actor_type']);$this->assertSame('admin',$row->metadata['guard']);$this->assertSame($id,$row->metadata['actor_id']);
    }
    public function test_colliding_admin_and_user_ids_and_parallel_guard_do_not_leak_identity():void
    {
        $user=User::factory()->create();$admin=new AdminUser(['name'=>'Synthetic owner','email'=>'collision-guard@example.test','password'=>'SyntheticOnly123!','role'=>'super_admin']);$admin->id=$user->id;$admin->save();
        $this->actingAs($user,'web');event(new Login('admin',$admin,false));
        $row=SecurityLog::where('action','login')->latest('id')->firstOrFail();$this->assertNull($row->user_id);$this->assertSame('saas_admin',$row->metadata['actor_type']);
        event(new Login('web',$user,false));$row=SecurityLog::where('action','login')->latest('id')->firstOrFail();$this->assertSame($user->id,$row->user_id);$this->assertSame('company_user',$row->metadata['actor_type']);$this->assertSame('web',$row->metadata['guard']);
    }
}
