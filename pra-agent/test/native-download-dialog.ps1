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
$condition = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ProcessIdProperty, $TargetProcessId)
$deadline = [DateTime]::UtcNow.AddSeconds(20)
while ([DateTime]::UtcNow -lt $deadline) {
  $nodes = [System.Windows.Automation.AutomationElement]::RootElement.FindAll([System.Windows.Automation.TreeScope]::Descendants, $condition)
  foreach ($window in $nodes) {
    if ($window.Current.ControlType -ne [System.Windows.Automation.ControlType]::Window -or $window.Current.Name -ne 'File save karne ka folder chunein') { continue }
    $controls = $window.FindAll([System.Windows.Automation.TreeScope]::Descendants, [System.Windows.Automation.Condition]::TrueCondition)
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
throw 'Own native Save As dialog/control did not appear; no substitute response used'
