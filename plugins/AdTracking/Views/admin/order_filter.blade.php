<el-form-item label="{{ __('AdTracking::common.source') }}">
  <el-input @keyup.enter.native="search" v-model="filter.ad_source" size="small"
            placeholder="{{ __('AdTracking::common.source') }}"></el-input>
</el-form-item>
<el-form-item label="{{ __('AdTracking::common.campaign') }}">
  <el-input @keyup.enter.native="search" v-model="filter.ad_campaign" size="small"
            placeholder="{{ __('AdTracking::common.campaign') }}"></el-input>
</el-form-item>
