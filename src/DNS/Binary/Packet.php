<?php

trait Packet
{
  public static final function deserialize(string $msg, int $off=0, ?int $rdLen=null): static
  {
    $reader = DnsReader::of($msg, $off, $rdLen);
    $obj = static::fromReader($reader);
  //if ($r->remaining() > 0)
  //  throw new RuntimeException('RDATA прочитано больше, чем заявлено в RDLENGTH');
    
    return $obj;
  }

};