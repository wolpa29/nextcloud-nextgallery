import { Column, getNavigation } from '@nextcloud/files'
import {registerStatusColumn} from './ai-status-column.mjs'
registerStatusColumn(Column,getNavigation())
