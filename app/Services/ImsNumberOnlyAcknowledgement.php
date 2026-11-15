<?php
namespace App\Services;
final class ImsNumberOnlyAcknowledgement {
 /** Exact observed acknowledgement only; environment authority is supplied by caller. */
 public static function number($response, string $posId, ?string $diagnostic, array $fields): ?string {
  if ($diagnostic !== 'response_received' || $fields !== ['FBRInvoiceNumber:string'] || !is_array($response)
   || !in_array(array_keys($response), [['InvoiceNumber'], ['FBRInvoiceNumber']], true)) return null;
  $number=FbrPosFiscalResult::fromResponse($response); $pos=str_pad($posId,6,'0',STR_PAD_LEFT);
  return $number && preg_match('/^\d{6}$/D',$pos) && str_starts_with($number,$pos) && preg_match('/[0-9]/',substr($number,6)) ? $number : null;
 }
}
