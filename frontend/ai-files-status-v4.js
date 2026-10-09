import { Column, getNavigation } from '@nextcloud/files-v4'
import {registerStatusColumn} from './ai-status-column.mjs'
import './ai-metadata-v4.js'
registerStatusColumn(Column,getNavigation())
