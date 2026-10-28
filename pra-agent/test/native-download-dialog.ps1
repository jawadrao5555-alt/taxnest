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
      foreach ($control in $controls) {
        if ($control.Current.ControlType -eq [System.Windows.Automation.ControlType]::Edit -and $control.Current.AutomationId -eq '1001') {
          $control.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).SetValue($target)
          $edited = $true
          break
        }
      }
      if (-not $edited) { continue }
    }
    foreach ($control in $controls) {
      if ($control.Current.ControlType -eq [System.Windows.Automation.ControlType]::Button -and $control.Current.Name.Replace('&','').Trim() -eq $Action) {
        $control.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke()
        exit 0
      }
    }
  }
  Start-Sleep -Milliseconds 100
}
Write-Output ('Own fictional Save As controls: ' + $lastControls)
Write-Output ('Own fixture windows: ' + [NativeDownloadDialog]::Describe([uint32]$TargetProcessId))
throw 'Own native Save As dialog/control did not appear; no substitute response used'
