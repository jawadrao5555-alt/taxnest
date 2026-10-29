param([int]$TargetProcessId, [ValidateSet('Save','Cancel')][string]$Action, [string]$FixtureRoot, [string]$Destination)
$ErrorActionPreference = 'Stop'
# Only an exact own-process fixture dialog and a generated temp directory.
$root = [IO.Path]::GetFullPath($FixtureRoot)
$target = [IO.Path]::GetFullPath($Destination)
if (-not ([IO.Path]::GetFileName($root).StartsWith('taxnest-native-download-')) -or
    -not $root.StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase) -or
    -not $target.StartsWith(($root + [IO.Path]::DirectorySeparatorChar), [StringComparison]::OrdinalIgnoreCase)) { throw 'Not a disposable download fixture' }
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
# Owned common dialogs are not reliably returned by a desktop PID descendant query.
# Resolve a visible native HWND by exact PID/title, then bind UIA to that HWND.
Add-Type @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class NativeDownloadDialog {
  public delegate bool EnumProc(IntPtr hwnd, IntPtr param);
  [DllImport("user32.dll")] static extern bool EnumWindows(EnumProc proc, IntPtr param);
  [DllImport("user32.dll")] static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] static extern int GetWindowText(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll")] static extern bool IsWindowVisible(IntPtr h);
  [DllImport("user32.dll")] static extern bool IsChild(IntPtr parent, IntPtr child);
  [DllImport("user32.dll")] static extern int GetDlgCtrlID(IntPtr h);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] static extern int GetClassName(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] static extern IntPtr SendMessage(IntPtr h, uint m, IntPtr w, string l);
  [DllImport("user32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern IntPtr SendMessageTimeout(IntPtr h, uint m, UIntPtr w, StringBuilder l, uint flags, uint timeout, out UIntPtr result);
  [DllImport("user32.dll")] static extern bool PostMessage(IntPtr h, uint m, IntPtr w, IntPtr l);
  static bool OwnControl(IntPtr dialog, IntPtr child, uint pid, string expectedClass, int id) {
    uint owner;GetWindowThreadProcessId(child,out owner);
    var cls=new StringBuilder(128);GetClassName(child,cls,cls.Capacity);
    return child!=IntPtr.Zero && owner==pid && IsChild(dialog,child) && GetDlgCtrlID(child)==id && cls.ToString()==expectedClass;
  }
  static bool ReadFilename(IntPtr child,string value) {
    var text=new StringBuilder(32768);UIntPtr copied;
    if(SendMessageTimeout(child,0x000D,new UIntPtr((uint)text.Capacity),text,0x0002,2000,out copied)==IntPtr.Zero)return false;
    return copied.ToUInt64()==(ulong)value.Length && text.ToString()==value;
  }
  public static bool Click(IntPtr dialog, IntPtr child, uint pid, int id) {
    return OwnControl(dialog,child,pid,"Button",id) && PostMessage(child,0x00F5,IntPtr.Zero,IntPtr.Zero);
  }
  [StructLayout(LayoutKind.Sequential)] struct MouseInput { public int dx,dy; public uint data,flags,time; public UIntPtr extra; }
  [StructLayout(LayoutKind.Sequential)] struct KeyInput { public ushort key,scan; public uint flags,time; public UIntPtr extra; }
  [StructLayout(LayoutKind.Explicit)] struct InputData {
    [FieldOffset(0)] public MouseInput mouse;
    [FieldOffset(0)] public KeyInput keyboard;
  }
  [StructLayout(LayoutKind.Sequential)] struct Input { public uint type; public InputData data; }
  [StructLayout(LayoutKind.Sequential)] struct Point { public int x,y; public Point(int a,int b){x=a;y=b;} }
  [DllImport("user32.dll")] static extern bool SetForegroundWindow(IntPtr h);
  [DllImport("user32.dll")] static extern IntPtr GetForegroundWindow();
  [DllImport("user32.dll")] static extern IntPtr WindowFromPoint(Point point);
  [DllImport("user32.dll")] static extern bool SetCursorPos(int x,int y);
  [DllImport("user32.dll", SetLastError=true)] static extern uint SendInput(uint count, Input[] inputs, int size);
  public static bool ClickPoint(IntPtr dialog,uint pid,int x,int y) {
    // Actual OS pointer input, only over this exact own-process modal dialog.
    if(Find(pid)!=dialog)return false;
    SetForegroundWindow(dialog);
    if(GetForegroundWindow()!=dialog)return false;
    IntPtr hit=WindowFromPoint(new Point(x,y));uint owner;GetWindowThreadProcessId(hit,out owner);
    if(owner!=pid || (hit!=dialog && !IsChild(dialog,hit)))return false;
    if(!SetCursorPos(x,y))return false;
    var clicks=new Input[2];
    clicks[0].data.mouse.flags=0x0002;clicks[1].data.mouse.flags=0x0004;
    return SendInput(2,clicks,Marshal.SizeOf(typeof(Input)))==2;
  }
  static Input Key(ushort key,ushort scan,uint flags) {
    var input=new Input();input.type=1;input.data.keyboard.key=key;input.data.keyboard.scan=scan;input.data.keyboard.flags=flags;return input;
  }
  public static bool TypeFilename(IntPtr dialog,IntPtr child,uint pid,int x,int y,string value) {
    if(value.Length==0 || value.Length>32767 || !OwnControl(dialog,child,pid,"Edit",1001))return false;
    if(!ClickPoint(dialog,pid,x,y))return false;
    // Real focus, Ctrl+A and Unicode keyboard events. WM_SETTEXT alone can
    // change Edit text without committing the shell dialog's filename state.
    var keys=new Input[4+value.Length*2];
    keys[0]=Key(0x11,0,0);keys[1]=Key(0x41,0,0);keys[2]=Key(0x41,0,2);keys[3]=Key(0x11,0,2);
    for(int i=0;i<value.Length;i++){keys[4+i*2]=Key(0,value[i],4);keys[5+i*2]=Key(0,value[i],6);}
    if(SendInput((uint)keys.Length,keys,Marshal.SizeOf(typeof(Input)))!=(uint)keys.Length)return false;
    for(int i=0;i<50;i++){if(ReadFilename(child,value))return true;System.Threading.Thread.Sleep(20);}
    return false;
  }
  public static string Describe(uint pid) {
    var result=new StringBuilder();
    EnumWindows((h,p)=>{uint owner;GetWindowThreadProcessId(h,out owner);
      if(owner==pid && IsWindowVisible(h)) {var title=new StringBuilder(2048);GetWindowText(h,title,title.Capacity);result.Append(title).Append(" | ");}
      return true;},IntPtr.Zero);
    return result.ToString();
  }
  public static IntPtr Find(uint pid) {
    IntPtr result=IntPtr.Zero;
    EnumWindows((h,p)=>{uint owner;GetWindowThreadProcessId(h,out owner);
      var title=new StringBuilder(2048);GetWindowText(h,title,title.Capacity);
      if(owner==pid && IsWindowVisible(h) && title.ToString()=="File save karne ka folder chunein") {result=h;return false;}
      return true;},IntPtr.Zero);
    return result;
  }
}
'@
$deadline = [DateTime]::UtcNow.AddSeconds(20)
while ([DateTime]::UtcNow -lt $deadline) {
  $handle = [NativeDownloadDialog]::Find([uint32]$TargetProcessId)
  if ($handle -ne [IntPtr]::Zero) {
    $window = [System.Windows.Automation.AutomationElement]::FromHandle($handle)
    $controls = $window.FindAll([System.Windows.Automation.TreeScope]::Descendants, [System.Windows.Automation.Condition]::TrueCondition)
    $lastControls = ($controls | ForEach-Object { $_.Current.ControlType.ProgrammaticName + ':' + $_.Current.AutomationId + ':' + $_.Current.Name }) -join ' | '
    if ($Action -eq 'Save') {
      $edited = $false
      foreach ($hostControl in $controls) {
        # There is another AutomationId=1001 in the address bar. Scope to the
        # actual filename host before accepting Value/Legacy/native providers.
        if ($hostControl.Current.AutomationId -ne 'FileNameControlHost') { continue }
        $fields = $hostControl.FindAll([System.Windows.Automation.TreeScope]::Descendants, [System.Windows.Automation.Condition]::TrueCondition)
        foreach ($control in $fields) {
          if ($control.Current.AutomationId -ne '1001') { continue }
          $fieldRect = $control.Current.BoundingRectangle
          if ($fieldRect.Width -gt 0 -and $fieldRect.Height -gt 0) {
            $edited = [NativeDownloadDialog]::TypeFilename($handle,[IntPtr]$control.Current.NativeWindowHandle,[uint32]$TargetProcessId,[int]($fieldRect.X+$fieldRect.Width/2),[int]($fieldRect.Y+$fieldRect.Height/2),$target)
          }
          if ($edited) { break }
        }
        if ($edited) { break }
      }
      if (-not $edited) { continue }
    }
    $buttonId = if ($Action -eq 'Save') { '1' } else { '2' }
    foreach ($control in $controls) {
      # Windows can expose the actual button as Pane; exact native ID and
      # label still bind to the real dialog action, never a mocked result.
      if ($control.Current.AutomationId -eq $buttonId -and $control.Current.Name.Replace('&','').Trim() -eq $Action) {
        try { $control.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke(); exit 0 } catch {
          try { $control.GetCurrentPattern([System.Windows.Automation.LegacyIAccessiblePattern]::Pattern).DoDefaultAction(); exit 0 } catch {
            if ([NativeDownloadDialog]::Click($handle,[IntPtr]$control.Current.NativeWindowHandle,[uint32]$TargetProcessId,[int]$buttonId)) { exit 0 }
            # Some common-dialog providers expose no invokable pattern or HWND.
            # Click the observed button rectangle through real OS pointer input.
            # Foreground/PID/hit-test checks prevent clicking another window.
            $rect = $control.Current.BoundingRectangle
            if ($rect.Width -gt 0 -and $rect.Height -gt 0 -and
                [NativeDownloadDialog]::ClickPoint($handle,[uint32]$TargetProcessId,[int]($rect.X+$rect.Width/2),[int]($rect.Y+$rect.Height/2))) { exit 0 }

          }
        }
      }
    }
  }
  Start-Sleep -Milliseconds 100
}
Write-Output ('Own fictional Save As controls: ' + $lastControls)
Write-Output ('Own fixture windows: ' + [NativeDownloadDialog]::Describe([uint32]$TargetProcessId))
throw 'Own native Save As dialog/control did not appear; no substitute response used'
