<?php

declare(strict_types=1);

namespace SkyDiablo\Keymile\Koap\Transport\Mtp;

/**
 * MTP request/response types (bits 0-6 of the command byte).
 * Bit 7 of the command byte indicates direction: 0 = to NE, 1 = from NE.
 */
enum MtpMessageType: int
{
    case Init = 0x01;
    case Login = 0x02;
    case Logout = 0x03;
    case Data = 0x04;
    case Notification = 0x05;
    case Close = 0x06;
    case Cancel = 0x07;
    case DataCancelResponse = 0x08;
    case BusyWait = 0x09;
    case RemoteLogin = 0x0A;
    case Loopback = 0x10;

    case CommandError = 0x40;
    case HeaderError = 0x41;
    case VersionError = 0x42;
    case SizeError = 0x43;
}
