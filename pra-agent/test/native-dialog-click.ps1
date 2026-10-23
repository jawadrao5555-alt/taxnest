param([int]$TargetProcessId, [string]$ExpectedCompany, [string]$Button)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
$deadline = [DateTime]::UtcNow.AddSeconds(20)
while ([DateTime]::UtcNow -lt $deadline) {
  $filter = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ProcessIdProperty, $TargetProcessId)
  $windows = [System.Windows.Automation.AutomationElement]::RootElement.FindAll([System.Windows.Automation.TreeScope]::Children, $filter)
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
  Start-Sleep -Milliseconds 100
}
throw 'Expected native company confirmation/button did not appear'
