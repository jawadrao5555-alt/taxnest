param([int]$TargetProcessId, [string]$ExpectedCompany, [string]$Button)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
# Owned native TaskDialogs may be below their owner in the UIA tree. The
# Win32 fallback still targets the exact PID, title, company text and button;
# it invokes a real native Button control, never substitutes a dialog response.
Add-Type @'
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Text;
public static class NativePairingDialog {
  public delegate bool EnumProc(IntPtr hwnd, IntPtr param);
  [DllImport("user32.dll")] static extern bool EnumWindows(EnumProc proc, IntPtr param);
  [DllImport("user32.dll")] static extern bool EnumChildWindows(IntPtr parent, EnumProc proc, IntPtr param);
  [DllImport("user32.dll")] static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] static extern int GetWindowText(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] static extern int GetClassName(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll")] static extern bool IsWindowVisible(IntPtr h);
  [DllImport("user32.dll")] static extern bool IsWindowEnabled(IntPtr h);
  [DllImport("user32.dll")] static extern bool PostMessage(IntPtr h, uint message, IntPtr w, IntPtr l);
  static string Text(IntPtr h) { var s=new StringBuilder(2048); GetWindowText(h,s,s.Capacity); return s.ToString(); }
  public static string Describe(uint pid) {
    var rows=new List<string>();
    EnumWindows((h,p)=>{uint owner;GetWindowThreadProcessId(h,out owner);
      if(owner==pid) {var cls=new StringBuilder(128);GetClassName(h,cls,cls.Capacity);rows.Add(cls+" | "+Text(h)+" | visible="+IsWindowVisible(h));}
      return true;},IntPtr.Zero);
    return String.Join("; ",rows);
  }
  public static bool Click(uint pid, string company, string button) {
    bool found=false;
    EnumWindows((h,p)=>{
      uint owner; GetWindowThreadProcessId(h,out owner);
      if(owner!=pid || !IsWindowVisible(h) || Text(h)!="Connect TaxNest Agent")return true;
      var children=new List<IntPtr>(); string all=Text(h);
      EnumChildWindows(h,(child,q)=>{children.Add(child);all+=" "+Text(child);return true;},IntPtr.Zero);
      if(!all.Contains(company))return true;
      foreach(var child in children) {
        var cls=new StringBuilder(128);GetClassName(child,cls,cls.Capacity);
        if(cls.ToString()=="Button" && IsWindowEnabled(child) && Text(child).Replace("&","")==button) {
          // BM_CLICK is delivered to this actual native dialog button.
          found=PostMessage(child,0x00F5,IntPtr.Zero,IntPtr.Zero);return false;
        }
      }
      return true;
    },IntPtr.Zero);
    return found;
  }
}
'@
$deadline = [DateTime]::UtcNow.AddSeconds(20)
while ([DateTime]::UtcNow -lt $deadline) {
  $filter = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ProcessIdProperty, $TargetProcessId)
  $windows = [System.Windows.Automation.AutomationElement]::RootElement.FindAll([System.Windows.Automation.TreeScope]::Descendants, $filter)
  foreach ($window in $windows) {
    if ($window.Current.Name -ne 'Connect TaxNest Agent') { continue }
    $nodes = $window.FindAll([System.Windows.Automation.TreeScope]::Descendants, [System.Windows.Automation.Condition]::TrueCondition)
    $names = @($nodes | ForEach-Object { $_.Current.Name })
    if (-not (($names -join ' ').Contains($ExpectedCompany))) { throw 'Native confirmation does not identify intended fixture company' }
    foreach ($node in $nodes) {
      if ($node.Current.ControlType -eq [System.Windows.Automation.ControlType]::Button -and $node.Current.Name.Replace('&','') -eq $Button) {
        $node.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke()
        exit 0
      }
    }
  }
  if ([NativePairingDialog]::Click([uint32]$TargetProcessId, $ExpectedCompany, $Button)) { exit 0 }
  Start-Sleep -Milliseconds 100
}
Write-Error ('Own-process native window diagnostics: ' + [NativePairingDialog]::Describe([uint32]$TargetProcessId)) -ErrorAction Continue
throw 'Expected native company confirmation/button did not appear'
