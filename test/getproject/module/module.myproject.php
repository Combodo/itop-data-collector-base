<?php

// Copyright (C) 2014 Combodo SARL
//
//   This program is free software; you can redistribute it and/or modify
//   it under the terms of the GNU General Public License as published by
//   the Free Software Foundation; version 3 of the License.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of the GNU General Public License
//   along with this program; if not, write to the Free Software
//   Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

SetupWebPage::AddModule(
    __FILE__, // Path to the current file, all other file names are relative to the directory containing this file
    'centreon-collector/1.0.0',
    [
        // Identification
        //
        'label'                => 'Centreon collector',
        'category'             => 'collector',

        // Setup
        //
        'dependencies'         => [],
        'mandatory'            => false,
        'visible'              => false,

        // Components
        //
        'datamodel'            => [],
        'webservice'           => [],
        'data.struct'          => [// add your 'structure' definition XML files here,
        ],
        'data.sample'          => [// add your sample data XML files here,
        ],

        // Documentation
        //
        'doc.manual_setup'     => '', // hyperlink to manual setup documentation, if any
        'doc.more_information' => '', // hyperlink to more information, if any

        // Default settings
        //
        'settings'             => [// Module specific settings go here, if any
        ],
    ]
);
