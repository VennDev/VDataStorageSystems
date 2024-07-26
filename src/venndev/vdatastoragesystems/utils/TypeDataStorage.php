<?php

declare(strict_types=1);

namespace venndev\vdatastoragesystems\utils;

final class TypeDataStorage
{

    public const TYPE_DETECT = -1; //Detect by file extension

    public const TYPE_PROPERTIES = 0; // .properties

    public const TYPE_JSON = 1; // .js, .json

    public const TYPE_YAML = 2; // .yml, .yaml

    public const TYPE_SERIALIZED = 3; // .sl

    public const TYPE_ENUM = 4; // .txt, .list, .enum

    public const TYPE_MYSQL = 5;

    public const TYPE_SQLITE = 6;

}